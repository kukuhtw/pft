<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Services;

use App\Models\User;
use App\Modules\Accounts\Models\FinancialAccount;
use App\Modules\Budgets\Services\BudgetService;
use App\Modules\Categories\Models\TransactionCategory;
use App\Modules\Interpretation\Enums\TransactionIntent;
use App\Modules\Interpretation\Services\EntryInterpretationService;
use App\Modules\Interpretation\Services\IntentPostingService;
use App\Modules\Reporting\Services\FinancialSummaryService;
use App\Modules\Shared\Domain\Money;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Shared\Support\MoneyInput;
use App\Modules\Transactions\Data\TransactionInput;
use App\Modules\Transactions\Enums\Channel;
use App\Modules\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Handles one Telegram update. Conversation:
 *   free text → interpretation → missing choices as buttons → summary → ✅ Simpan → posted.
 * Nothing is posted without the confirmation tap. Account/category ids coming back in callbacks
 * are accepted only if they are among the choices computed for the linked user.
 *
 * Privacy: only private chats are served; message text is never logged or stored apart from
 * the transaction description the user confirms; drafts expire from the cache after 15 minutes.
 */
final readonly class TelegramBotService
{
    private const int DRAFT_TTL_MINUTES = 15;

    private const string WARNING_TEXT_DEFAULT = 'Periksa kembali jenis transaksinya.';

    private const array WARNINGS = [
        'LENDING_OUT_NOT_SUPPORTED' => 'Sepertinya Anda meminjamkan uang (piutang). Ini belum didukung, jadi catat sebagai pengeluaran atau transfer dulu.',
        'RECEIVABLE_COLLECTION_NOT_SUPPORTED' => 'Sepertinya ini pengembalian piutang. Ini belum didukung, jadi catat sebagai pemasukan lainnya dulu.',
        'NO_OPEN_DEBT_FOR_COUNTERPARTY' => 'Tidak ada hutang aktif ke pihak ini. Pilih akun hutang yang dibayar.',
        'CHOOSE_DEBT_ACCOUNT' => 'Pihak ini punya lebih dari satu hutang. Pilih yang dibayar.',
        'TEXT_SUGGESTS_LOAN_RECEIVED' => 'Teks menyebut pinjaman, tapi dicatat sebagai pemasukan.',
        'TEXT_SUGGESTS_DEBT_REPAYMENT' => 'Teks menyebut bayar hutang, tapi dicatat sebagai pengeluaran.',
    ];

    public function __construct(
        private TelegramClient $telegram,
        private TelegramLinkService $links,
        private EntryInterpretationService $interpreter,
        private IntentPostingService $posting,
        private FinancialSummaryService $summaries,
        private BudgetService $budgets,
    ) {}

    /** @param  array<string, mixed>  $update */
    public function handle(array $update): void
    {
        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);

            return;
        }

        $message = $update['message'] ?? null;
        if (! is_array($message) || ! isset($message['chat']['id']) || ! is_string($message['text'] ?? null)) {
            return; // stickers, photos, edits, channel posts… are ignored
        }

        $chatId = (int) $message['chat']['id'];

        if (($message['chat']['type'] ?? '') !== 'private') {
            $this->telegram->sendMessage($chatId, 'Demi privasi, bot ini hanya melayani chat pribadi.');

            return;
        }

        if (! RateLimiter::attempt('telegram-chat:'.$chatId, 30, static fn (): bool => true, 60)) {
            return;
        }

        $text = trim(mb_substr($message['text'], 0, 500));
        [$command, $argument] = $this->parseCommand($text);

        if (in_array($command, ['/start', '/hubungkan'], true) && $argument !== '') {
            $this->linkChat($chatId, $argument);

            return;
        }

        $link = $this->links->forChat($chatId);

        if ($link === null) {
            $this->telegram->sendMessage($chatId, $this->notLinkedText());

            return;
        }

        $link->forceFill(['last_used_at' => now()])->save();
        $user = $link->user;

        match ($command) {
            '/start', '/bantuan', '/help' => $this->telegram->sendMessage($chatId, $this->helpText($user)),
            '/saldo' => $this->telegram->sendMessage($chatId, $this->balancesText($user)),
            '/ringkasan' => $this->telegram->sendMessage($chatId, $this->summaryText($user)),
            '/terakhir' => $this->telegram->sendMessage($chatId, $this->recentText($user)),
            '/batal' => $this->cancelDraft($chatId),
            '/putuskan' => $this->unlink($chatId, $user),
            null => $this->handleText($chatId, $user, $text),
            default => $this->telegram->sendMessage($chatId, 'Perintah tidak dikenal. Ketik /bantuan.'),
        };
    }

    // ── Linking ──────────────────────────────────────────────────────────────

    private function linkChat(int $chatId, string $code): void
    {
        if (! RateLimiter::attempt('telegram-link:'.$chatId, 5, static fn (): bool => true, 600)) {
            $this->telegram->sendMessage($chatId, 'Terlalu banyak percobaan. Coba lagi dalam 10 menit.');

            return;
        }

        $user = $this->links->link($code, $chatId);

        if ($user === null) {
            $this->telegram->sendMessage($chatId, 'Kode tidak valid atau sudah kedaluwarsa. Buat kode baru di web: Profil → Telegram.');

            return;
        }

        $this->telegram->sendMessage($chatId, "✅ Terhubung dengan akun <b>{$this->e($user->name)}</b>.\n\n".$this->helpText($user));
    }

    private function unlink(int $chatId, User $user): void
    {
        Cache::forget($this->draftKey($chatId));
        $this->links->unlink($user, 'telegram_command');
        $this->telegram->sendMessage($chatId, 'Telegram diputus dari akun Anda. Untuk menghubungkan lagi, buat kode baru di web: Profil → Telegram.');
    }

    // ── Entry drafts ─────────────────────────────────────────────────────────

    private function handleText(int $chatId, User $user, string $text): void
    {
        $draft = Cache::get($this->draftKey($chatId));

        // Waiting for the amount of an existing draft.
        if (is_array($draft) && ($draft['awaiting'] ?? null) === 'amount') {
            $money = MoneyInput::parse($text, $this->currency());
            if ($money !== null && $money->isPositive()) {
                $draft['amount'] = $money->amount;
                $draft['awaiting'] = null;
                $this->advance($chatId, null, $user, $draft);

                return;
            }
        }

        $result = $this->interpreter->interpret($user, $text, $this->currency());
        $intent = TransactionIntent::tryFrom((string) ($result['intent'] ?? ''));

        $blocking = array_intersect($result['warnings'], ['LENDING_OUT_NOT_SUPPORTED', 'RECEIVABLE_COLLECTION_NOT_SUPPORTED']);
        if ($blocking !== []) {
            $this->telegram->sendMessage($chatId, '⚠ '.self::WARNINGS[reset($blocking)]);

            return;
        }

        $draft = [
            'intent' => $intent?->value,
            'amount' => $result['amount']?->amount,
            'date' => CarbonImmutable::now($this->timezone())->toDateString(),
            'counterparty' => $result['counterparty']['name'] ?? null,
            'activity' => $result['activity'] ?? null,
            'category_id' => $result['category']['id'] ?? null,
            'source' => null,
            'destination' => null,
            'debt_account_id' => $result['suggested_request']['body']['debt_account_id'] ?? null,
            'debt_choices' => array_column($result['counterparty']['debt_accounts'] ?? [], 'id'),
            'description' => Str::limit($text, 255, ''),
            'warnings' => array_values(array_intersect($result['warnings'], array_keys(self::WARNINGS))),
            'awaiting' => null,
            'key' => 'tg-'.Str::uuid()->toString(),
        ];

        $this->advance($chatId, null, $user, $draft);
    }

    /** @param  array<string, mixed>  $callback */
    private function handleCallback(array $callback): void
    {
        $chatId = (int) ($callback['message']['chat']['id'] ?? 0);
        $messageId = (int) ($callback['message']['message_id'] ?? 0);
        $data = (string) ($callback['data'] ?? '');
        $this->telegram->answerCallback((string) ($callback['id'] ?? ''));

        if ($chatId === 0 || ($callback['message']['chat']['type'] ?? '') !== 'private'
            || ! RateLimiter::attempt('telegram-chat:'.$chatId, 30, static fn (): bool => true, 60)) {
            return;
        }

        $link = $this->links->forChat($chatId);
        $draft = Cache::get($this->draftKey($chatId));

        if ($link === null) {
            $this->telegram->editMessage($chatId, $messageId, $this->notLinkedText());

            return;
        }

        if (! is_array($draft)) {
            $this->telegram->editMessage($chatId, $messageId, 'Draf ini sudah kedaluwarsa atau sudah diproses. Ketik ulang transaksinya.');

            return;
        }

        $user = $link->user;
        [$action, $value] = array_pad(explode(':', $data, 2), 2, '');

        switch ($action) {
            case 'no':
                Cache::forget($this->draftKey($chatId));
                $this->telegram->editMessage($chatId, $messageId, 'Dibatalkan. Tidak ada yang dicatat.');

                return;
            case 'ok':
                $this->confirm($chatId, $messageId, $user, $draft);

                return;
            case 'chg':
                $this->telegram->editMessage($chatId, $messageId, 'Pilih jenis transaksi:', $this->intentKeyboard());

                return;
            case 'i':
                $intent = TransactionIntent::tryFrom($value);
                if ($intent === null || $intent === TransactionIntent::OPENING_BALANCE) {
                    return;
                }
                $draft = [...$draft, 'intent' => $intent->value, 'source' => null, 'destination' => null, 'category_id' => null, 'warnings' => []];
                if (! IntentPostingService::needsParty($intent)) {
                    $draft['debt_account_id'] = null;
                }
                break;
            case 's':
            case 'd':
            case 'x':
                $field = ['s' => 'source', 'd' => 'destination', 'x' => 'debt_account_id'][$action];
                if (! $this->choices($user, $draft, $field)->contains('id', $value)) {
                    return; // stale or forged button
                }
                $draft[$field] = $value;
                break;
            default:
                return;
        }

        $this->advance($chatId, $messageId, $user, $draft);
    }

    /**
     * Fills the next missing piece (asking with buttons when there is a real choice),
     * then shows the confirmation summary.
     *
     * @param  array<string, mixed>  $draft
     */
    private function advance(int $chatId, ?int $messageId, User $user, array $draft): void
    {
        $intent = TransactionIntent::tryFrom((string) $draft['intent']);
        $say = fn (string $text, ?array $keyboard = null) => $messageId !== null
            ? $this->telegram->editMessage($chatId, $messageId, $text, $keyboard)
            : $this->telegram->sendMessage($chatId, $text, $keyboard);

        if ($intent === null) {
            $this->saveDraft($chatId, $draft);
            $say("Saya belum yakin maksudnya. Pilih jenis transaksi:\n<i>{$this->e($draft['description'])}</i>", $this->intentKeyboard());

            return;
        }

        if ($draft['amount'] === null) {
            $this->saveDraft($chatId, [...$draft, 'awaiting' => 'amount']);
            $say("{$intent->label()}: nominalnya berapa? Contoh: <code>50000</code>, <code>1,5jt</code>, <code>500rb</code>.");

            return;
        }

        if ($intent === TransactionIntent::LOAN_RECEIVED && $draft['counterparty'] === null && $draft['debt_account_id'] === null) {
            Cache::forget($this->draftKey($chatId));
            $say('Pinjaman dari siapa? Tulis ulang dengan nama pihaknya, misalnya: <code>pinjam dari Budi 2jt</code>.');

            return;
        }

        foreach ([
            'source' => [IntentPostingService::needsSource($intent), $intent === TransactionIntent::INTERNAL_TRANSFER ? 'Dari akun mana?' : 'Dibayar dari akun mana?', 's'],
            'destination' => [IntentPostingService::needsDestination($intent), 'Masuk ke akun mana?', 'd'],
            'debt_account_id' => [$intent === TransactionIntent::DEBT_REPAYMENT && ($draft['counterparty'] === null || count($draft['debt_choices']) !== 1), 'Hutang mana yang dibayar?', 'x'],
        ] as $field => [$needed, $question, $prefix]) {
            if (! $needed || $draft[$field] !== null) {
                continue;
            }

            $choices = $this->choices($user, $draft, $field);

            if ($choices->isEmpty()) {
                Cache::forget($this->draftKey($chatId));
                $say($field === 'debt_account_id'
                    ? 'Tidak ada hutang aktif yang bisa dibayar. Cek akun Anda di web: menu Akun.'
                    : 'Belum ada akun yang cocok. Buat rekening, dompet tunai, atau e-wallet dulu di web: menu Akun.');

                return;
            }

            if ($choices->count() === 1 && $field !== 'debt_account_id') {
                $draft[$field] = $choices->first()->id;

                continue;
            }

            $this->saveDraft($chatId, $draft);
            $keyboard = $choices->map(fn (FinancialAccount $a): array => [['text' => $this->accountLabel($a), 'callback_data' => $prefix.':'.$a->id]])->values()->all();
            $keyboard[] = [['text' => '❌ Batal', 'callback_data' => 'no']];
            $say("<b>{$intent->label()}</b> · {$this->money($draft['amount'])->format()}\n{$question}", $keyboard);

            return;
        }

        if (IntentPostingService::needsCategory($intent) && $draft['category_id'] === null) {
            $code = IntentPostingService::DEFAULT_CATEGORY[$intent->value] ?? null;
            $draft['category_id'] = $code === null ? null : TransactionCategory::query()
                ->where('user_id', $user->id)->where('code', $code)->where('is_active', true)->value('id');
        }

        $this->saveDraft($chatId, $draft);
        $say($this->summaryOf($user, $intent, $draft), [
            [['text' => '✅ Simpan', 'callback_data' => 'ok'], ['text' => '❌ Batal', 'callback_data' => 'no']],
            [['text' => '🔁 Ganti jenis', 'callback_data' => 'chg']],
        ]);
    }

    /** @param  array<string, mixed>  $draft */
    private function confirm(int $chatId, int $messageId, User $user, array $draft): void
    {
        $intent = TransactionIntent::tryFrom((string) $draft['intent']);
        $money = $draft['amount'] === null ? null : $this->money($draft['amount']);

        if ($intent === null || $money === null) {
            $this->advance($chatId, $messageId, $user, $draft);

            return;
        }

        Cache::forget($this->draftKey($chatId));

        try {
            $transaction = $this->posting->post($user, $intent, new TransactionInput(
                amount: $money,
                transactionDate: CarbonImmutable::createFromFormat('Y-m-d', (string) $draft['date'], $this->timezone()),
                sourceAccountId: $draft['source'],
                destinationAccountId: $draft['destination'],
                categoryId: $draft['category_id'],
                description: $draft['description'],
                channel: Channel::TELEGRAM,
                idempotencyKey: (string) $draft['key'],
                // Transfers move money between the user's own accounts: there is no other party.
                counterpartyName: $draft['counterparty'] === null || $intent === TransactionIntent::INTERNAL_TRANSFER ? null : Str::limit((string) $draft['counterparty'], 128, ''),
                activity: $intent === TransactionIntent::ACTIVITY_INCOME && $draft['activity'] !== null ? Str::limit((string) $draft['activity'], 64, '') : null,
                debtAccountId: $draft['debt_account_id'],
            ));
        } catch (DomainException $e) {
            $this->telegram->editMessage($chatId, $messageId, '❌ Tidak tersimpan: '.$this->e($e->getMessage())."\nKetik ulang transaksinya.");

            return;
        }

        $party = $transaction->counterparty?->name;
        $this->telegram->editMessage($chatId, $messageId, sprintf(
            "✅ Tersimpan: <b>%s</b> %s%s\nBatalkan lewat web (menu Transaksi) jika keliru.",
            $this->e($transaction->intent?->label() ?? $transaction->type->value),
            Money::fromDatabase($transaction->getRawOriginal('amount'), $transaction->currency_code)->format(),
            $party !== null ? ' · '.$this->e($party) : '',
        ));
    }

    private function cancelDraft(int $chatId): void
    {
        Cache::forget($this->draftKey($chatId));
        $this->telegram->sendMessage($chatId, 'Draf dibatalkan.');
    }

    /**
     * The only accounts a button may point to for this draft.
     *
     * @param  array<string, mixed>  $draft
     * @return Collection<int, FinancialAccount>
     */
    private function choices(User $user, array $draft, string $field): Collection
    {
        $intent = TransactionIntent::tryFrom((string) $draft['intent']);
        $assets = $this->posting->accounts($user, liability: false)->toBase();

        return match ($field) {
            'source' => $intent === TransactionIntent::EXPENSE
                ? $assets->merge($this->posting->accounts($user, liability: true)->where('account_type_code', 'CREDIT_CARD'))
                : $assets,
            'destination' => $assets->reject(static fn (FinancialAccount $a): bool => $a->id === $draft['source'])->values(),
            'debt_account_id' => (function () use ($user, $draft): Collection {
                $debts = $this->posting->accounts($user, liability: true)->toBase()->filter(static fn (FinancialAccount $a): bool => $a->balance()->isPositive());
                $own = $debts->whereIn('id', $draft['debt_choices']);

                return ($own->isNotEmpty() ? $own : $debts)->values();
            })(),
            default => collect(),
        };
    }

    // ── Read-only commands ───────────────────────────────────────────────────

    private function balancesText(User $user): string
    {
        $lines = ['<b>Saldo akun</b>'];
        foreach ([false, true] as $liability) {
            foreach ($this->posting->accounts($user, $liability) as $account) {
                $lines[] = sprintf('%s %s: %s', $liability ? '🔻' : '•', $this->e($account->name), $account->balance()->format());
            }
        }

        $summary = $this->summaries->summarize($user, $this->now()->startOfMonth(), $this->now(), $this->currency());
        $lines[] = '';
        $lines[] = 'Kekayaan bersih: <b>'.$summary->netWorth->format().'</b>';
        $lines[] = '<i>🔻 = hutang (sisa yang harus dibayar)</i>';

        return count($lines) === 4 ? 'Belum ada akun. Buat akun pertama di web: menu Akun.' : implode("\n", $lines);
    }

    private function summaryText(User $user): string
    {
        $s = $this->summaries->summarize($user, $this->now()->startOfMonth(), $this->now(), $this->currency());
        $lines = [
            '<b>Ringkasan '.$this->now()->translatedFormat('F Y').'</b>',
            'Pemasukan: '.$s->totalIncome->format(),
            'Pengeluaran: '.$s->totalExpenses->format(),
            'Selisih: <b>'.$s->netIncome->format().'</b>',
        ];

        $alerts = array_filter($this->budgets->status($user, $this->now()->format('Y-m'))['rows'], static fn (array $r): bool => in_array($r['state'], ['warning', 'over'], true));
        if ($alerts !== []) {
            $lines[] = '';
            $lines[] = '<b>Budget perlu perhatian</b>';
            foreach ($alerts as $row) {
                $lines[] = sprintf('%s %s: %d%% (%s / %s)', $row['state'] === 'over' ? '✕' : '⚠', $this->e($row['category']->name), $row['percent'], $row['actual']->format(), $row['budget']->format());
            }
        }

        return implode("\n", $lines);
    }

    private function recentText(User $user): string
    {
        $rows = Transaction::query()->with('counterparty')
            ->where('user_id', $user->id)
            ->orderByDesc('transaction_date')->orderByDesc('id')->limit(5)->get();

        if ($rows->isEmpty()) {
            return 'Belum ada transaksi.';
        }

        return "<b>5 transaksi terakhir</b>\n".$rows->map(fn (Transaction $t): string => sprintf(
            '%s · %s · %s%s',
            $t->transaction_date->translatedFormat('d M'),
            $this->e($t->intent?->label() ?? $t->type->value),
            Money::fromDatabase($t->getRawOriginal('amount'), $t->currency_code)->format(),
            $t->counterparty !== null ? ' · '.$this->e($t->counterparty->name) : '',
        ))->implode("\n");
    }

    // ── Texts & helpers ──────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $draft */
    private function summaryOf(User $user, TransactionIntent $intent, array $draft): string
    {
        $accounts = $this->posting->accounts($user, false)->merge($this->posting->accounts($user, true))->keyBy('id');
        $category = $draft['category_id'] === null ? null : TransactionCategory::query()->where('user_id', $user->id)->find($draft['category_id']);
        $date = CarbonImmutable::createFromFormat('Y-m-d', (string) $draft['date'], $this->timezone());

        $lines = array_filter([
            '<b>Periksa sebelum disimpan</b>',
            'Jenis: '.$intent->label(),
            'Nominal: <b>'.$this->money($draft['amount'])->format().'</b>',
            'Tanggal: '.$date->translatedFormat('d M Y'),
            $draft['source'] !== null ? 'Dari: '.$this->e($accounts->get($draft['source'])?->name ?? '?') : null,
            $draft['destination'] !== null ? 'Ke: '.$this->e($accounts->get($draft['destination'])?->name ?? '?') : null,
            $draft['debt_account_id'] !== null ? 'Hutang: '.$this->e($accounts->get($draft['debt_account_id'])?->name ?? '?') : null,
            $category !== null ? 'Kategori: '.$this->e($category->name) : null,
            $draft['counterparty'] !== null && $intent !== TransactionIntent::INTERNAL_TRANSFER ? 'Pihak: '.$this->e($draft['counterparty']) : null,
            $intent === TransactionIntent::ACTIVITY_INCOME && $draft['activity'] !== null ? 'Aktivitas: '.$this->e($draft['activity']) : null,
        ]);

        foreach ($draft['warnings'] as $warning) {
            $lines[] = '⚠ '.(self::WARNINGS[$warning] ?? self::WARNING_TEXT_DEFAULT);
        }

        return implode("\n", $lines);
    }

    /** @return list<list<array{text: string, callback_data: string}>> */
    private function intentKeyboard(): array
    {
        $rows = array_map(
            static fn (array $pair): array => array_map(static fn (TransactionIntent $i): array => ['text' => $i->label(), 'callback_data' => 'i:'.$i->value], $pair),
            array_chunk(IntentPostingService::selectableIntents(), 2),
        );
        $rows[] = [['text' => '❌ Batal', 'callback_data' => 'no']];

        return $rows;
    }

    private function helpText(User $user): string
    {
        return implode("\n", [
            'Tulis transaksi seperti chat biasa, contoh:',
            '• <code>gaji November 10jt</code>',
            '• <code>makan siang 45rb</code>',
            '• <code>transfer BCA ke BNI 2jt</code>',
            '• <code>pinjam dari Budi 2jt</code>',
            '• <code>bayar hutang ke Budi 1jt</code>',
            '',
            'Saya akan menanyakan akunnya bila perlu, lalu menampilkan ringkasan. Tidak ada yang dicatat sebelum Anda menekan ✅ Simpan.',
            '',
            '/saldo: saldo semua akun',
            '/ringkasan: pemasukan, pengeluaran, dan budget bulan ini',
            '/terakhir: 5 transaksi terakhir',
            '/batal: batalkan draf',
            '/putuskan: putuskan Telegram dari akun',
            '',
            '<i>Jangan kirim PIN, OTP, kata sandi, atau nomor kartu lengkap ke chat ini.</i>',
        ]);
    }

    private function notLinkedText(): string
    {
        return "Chat ini belum terhubung ke akun mana pun.\n\n1. Masuk ke aplikasi web → <b>Profil</b> → <b>Telegram</b> → <b>Buat kode</b>.\n2. Kirim <code>/start KODE</code> ke chat ini, atau klik tautan yang muncul di web.";
    }

    /** @return array{0: ?string, 1: string} */
    private function parseCommand(string $text): array
    {
        if (! str_starts_with($text, '/')) {
            return [null, ''];
        }

        [$command, $argument] = array_pad(preg_split('/\s+/', $text, 2) ?: [], 2, '');

        return [strtolower(explode('@', $command)[0]), trim($argument)];
    }

    private function accountLabel(FinancialAccount $account): string
    {
        $suffix = $account->counterparty !== null ? ' · '.$account->counterparty->name : '';

        return Str::limit($account->name.$suffix, 60);
    }

    /** @param  array<string, mixed>  $draft */
    private function saveDraft(int $chatId, array $draft): void
    {
        Cache::put($this->draftKey($chatId), $draft, now()->addMinutes(self::DRAFT_TTL_MINUTES));
    }

    private function draftKey(int $chatId): string
    {
        return 'telegram:draft:'.$chatId;
    }

    private function money(string $amount): Money
    {
        return Money::of($amount, $this->currency());
    }

    private function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone());
    }

    private function timezone(): string
    {
        return (string) config('finance.display_timezone');
    }

    private function currency(): string
    {
        return (string) config('finance.default_currency');
    }
}
