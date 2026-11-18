<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Accounts\Models\FinancialAccount;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Budgets\Models\Budget;
use App\Modules\Categories\Models\TransactionCategory;
use App\Modules\Counterparties\Models\Counterparty;
use App\Modules\Shared\Domain\Money;
use App\Modules\Telegram\Models\TelegramLink;
use App\Modules\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads of the user's own data. Every export is tenant-scoped and audit-logged (ISO/IEC 27001 A.8.15);
 * the full export serves the data subject's right of access/portability (UU PDP).
 */
final class ExportController extends Controller
{
    /** Columns of the transaction CSV, in order. */
    private const array CSV_COLUMNS = [
        'tanggal', 'status', 'jenis_akuntansi', 'makna', 'nominal', 'mata_uang', 'akun_sumber', 'akun_tujuan',
        'kategori', 'pihak', 'aktivitas', 'keperluan', 'merchant', 'deskripsi', 'no_referensi', 'metode_bayar', 'kanal', 'id',
    ];

    public function transactionsCsv(Request $request, AuditLogger $audit): StreamedResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $audit->record('export.transactions_csv', $user, null, ['from' => $data['from'], 'to' => $data['to']]);

        $query = Transaction::query()
            ->with(['sourceAccount', 'destinationAccount', 'category', 'counterparty', 'merchant'])
            ->where('user_id', $user->id)
            ->whereBetween('transaction_date', [$data['from'], $data['to']])
            ->orderBy('transaction_date')
            ->orderBy('id');

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Indonesian names correctly
            fputcsv($out, self::CSV_COLUMNS, ',', '"', '');

            $query->chunk(500, function ($chunk) use ($out): void {
                foreach ($chunk as $t) {
                    /** @var Transaction $t */
                    fputcsv($out, array_map(self::cell(...), [
                        $t->transaction_date->toDateString(),
                        $t->status->value,
                        $t->type->value,
                        $t->intent?->value,
                        Money::fromDatabase($t->getRawOriginal('amount'), $t->currency_code)->amount,
                        $t->currency_code,
                        $t->sourceAccount?->name,
                        $t->destinationAccount?->name,
                        $t->category?->name,
                        $t->counterparty?->name,
                        $t->activity,
                        $t->purpose,
                        $t->merchant?->name,
                        $t->description,
                        $t->reference_number,
                        $t->payment_method?->value,
                        $t->channel->value,
                        $t->id,
                    ]), ',', '"', '');
                }
            });

            fclose($out);
        }, sprintf('transaksi_%s_%s.csv', $data['from'], $data['to']), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Everything the app stores about the user, as one JSON document. Secrets are never included. */
    public function myData(Request $request, AuditLogger $audit): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $audit->record('export.personal_data', $user, $user);

        $payload = [
            'exported_at' => CarbonImmutable::now()->toIso8601String(),
            'user' => ['id' => $user->public_id, 'name' => $user->name, 'email' => $user->email, 'created_at' => $user->created_at?->toIso8601String()],
            'accounts' => FinancialAccount::query()->with(['ledgerAccount', 'institution', 'counterparty'])->where('user_id', $user->id)->get()->map(static fn (FinancialAccount $a): array => [
                'id' => $a->id, 'name' => $a->name, 'type' => $a->account_type_code, 'institution' => $a->institution?->code,
                'owed_to' => $a->counterparty?->name, 'currency' => $a->currency_code, 'account_number_last4' => $a->account_number_last4,
                'balance' => $a->balance()->amount, 'status' => $a->status->value,
            ]),
            'categories' => TransactionCategory::query()->where('user_id', $user->id)->get(['id', 'code', 'name', 'kind', 'is_active']),
            'counterparties' => Counterparty::query()->where('user_id', $user->id)->get(['id', 'name', 'kind', 'aliases']),
            'telegram' => TelegramLink::query()->where('user_id', $user->id)->get()->map(static fn (TelegramLink $l): array => [
                'chat_id' => $l->chat_id, 'linked_at' => $l->linked_at->toIso8601String(), 'last_used_at' => $l->last_used_at?->toIso8601String(),
            ])->first(),
            'budgets' => Budget::query()->with('category:id,name')->where('user_id', $user->id)->get()->map(static fn (Budget $b): array => [
                'period' => $b->period, 'category' => $b->category->name, 'amount' => $b->money()->amount, 'currency' => $b->currency_code,
            ]),
            'transactions' => Transaction::query()->with(['counterparty:id,name', 'category:id,name'])->where('user_id', $user->id)->orderBy('transaction_date')->get()->map(static fn (Transaction $t): array => [
                'id' => $t->id, 'date' => $t->transaction_date->toDateString(), 'type' => $t->type->value, 'intent' => $t->intent?->value,
                'status' => $t->status->value, 'amount' => Money::fromDatabase($t->getRawOriginal('amount'), $t->currency_code)->amount, 'currency' => $t->currency_code,
                'source_account_id' => $t->source_account_id, 'destination_account_id' => $t->destination_account_id,
                'category' => $t->category?->name, 'counterparty' => $t->counterparty?->name, 'activity' => $t->activity, 'purpose' => $t->purpose,
                'description' => $t->description, 'reference_number' => $t->reference_number, 'channel' => $t->channel->value,
            ]),
        ];

        return response()->json($payload, 200, [
            'Content-Disposition' => 'attachment; filename="data-saya-'.now()->format('Ymd').'.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /** Neutralise spreadsheet formula injection: a cell starting with = + - @ tab or CR is prefixed with '. */
    private static function cell(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 && ! is_numeric($value) ? "'".$value : $value;
    }
}
