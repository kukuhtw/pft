<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use App\Modules\Accounts\Models\FinancialAccount;
use App\Modules\Categories\Enums\CategoryKind;
use App\Modules\Categories\Models\TransactionCategory;
use App\Modules\Interpretation\Enums\TransactionIntent;
use App\Modules\Interpretation\Services\EntryInterpretationService;
use App\Modules\Interpretation\Services\IntentPostingService;
use App\Modules\Shared\Domain\Money;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Shared\Support\MoneyInput;
use App\Modules\Transactions\Data\TransactionInput;
use App\Modules\Transactions\Enums\Channel;
use App\Modules\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Catat cepat": free text → the app's interpretation → user reviews/corrects → posted.
 * Nothing is posted until the user presses Simpan; the interpretation never bypasses the ledger rules.
 * All IDs coming from the browser are re-checked against the logged-in user by the services.
 */
final class QuickEntry extends Component
{
    public string $text = '';

    public string $intent = '';

    public string $amount = '';

    public string $transactionDate = '';

    public string $counterparty = '';

    public string $activity = '';

    /** Catatan hutang: what the loan is for (LOAN_RECEIVED). */
    public string $purpose = '';

    public string $description = '';

    public string $categoryId = '';

    public string $sourceAccountId = '';

    public string $destinationAccountId = '';

    public string $debtAccountId = '';

    /** @var list<string> */
    #[Locked]
    public array $warnings = [];

    #[Locked]
    public ?string $confidence = null;

    /** One key per proposal: double-clicking "Simpan" can never post twice. */
    #[Locked]
    public string $idempotencyKey = '';

    public ?string $flash = null;

    public function mount(): void
    {
        $this->resetForm();
    }

    public function interpret(EntryInterpretationService $interpreter): void
    {
        $this->validate(['text' => ['required', 'string', 'max:500']]);

        $result = $interpreter->interpret($this->user(), $this->text, (string) config('finance.default_currency'));
        $this->resetForm(keepText: true);

        $this->intent = (string) ($result['intent'] ?? '');
        $this->amount = $result['amount']?->amount !== null ? MoneyInput::format($result['amount']->amount) : '';
        $this->counterparty = (string) ($result['counterparty']['name'] ?? '');
        $this->activity = (string) ($result['activity'] ?? '');
        $this->purpose = (string) ($result['purpose'] ?? '');
        $this->description = Str::limit($this->text, 255, '');
        $this->categoryId = (string) ($result['category']['id'] ?? '');
        $this->debtAccountId = (string) ($result['suggested_request']['body']['debt_account_id'] ?? '');
        $this->warnings = $result['warnings'];
        $this->confidence = $result['confidence'];
        $this->flash = null;

        $this->applyIntentDefaults();
    }

    /** Intent changed by hand in the form. */
    public function updatedIntent(): void
    {
        $this->categoryId = '';
        $this->applyIntentDefaults();
    }

    public function save(IntentPostingService $posting): void
    {
        $intent = $this->validateForm();
        $money = MoneyInput::parse($this->amount, (string) config('finance.default_currency'));

        if ($money === null) {
            $this->addError('amount', 'Nominal tidak valid. Contoh: 50000, 2.000.000, 1,5jt, 500rb.');

            return;
        }

        $input = new TransactionInput(
            amount: $money,
            transactionDate: CarbonImmutable::createFromFormat('Y-m-d', $this->transactionDate, (string) config('finance.display_timezone')),
            sourceAccountId: $this->blankToNull($this->sourceAccountId),
            destinationAccountId: $this->blankToNull($this->destinationAccountId),
            categoryId: $this->blankToNull($this->categoryId),
            description: $this->blankToNull($this->description),
            channel: Channel::WEB,
            idempotencyKey: $this->idempotencyKey,
            counterpartyName: $intent === TransactionIntent::INTERNAL_TRANSFER ? null : $this->blankToNull($this->counterparty),
            activity: $intent === TransactionIntent::ACTIVITY_INCOME ? $this->blankToNull($this->activity) : null,
            debtAccountId: $this->blankToNull($this->debtAccountId),
            purpose: $intent === TransactionIntent::LOAN_RECEIVED ? $this->blankToNull($this->purpose) : null,
        );

        try {
            $transaction = $posting->post($this->user(), $intent, $input);
        } catch (DomainException $e) {
            $this->addError('save', $e->getMessage());

            return;
        }

        $this->flash = $this->successMessage($transaction);
        $this->resetForm();
        unset($this->assetAccounts, $this->debtAccounts);
        $this->dispatch('entry-posted');
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->flash = null;
    }

    /** @return Collection<int, FinancialAccount> */
    #[Computed]
    public function assetAccounts(): Collection
    {
        return $this->accounts(liability: false);
    }

    /** @return Collection<int, FinancialAccount> */
    #[Computed]
    public function debtAccounts(): Collection
    {
        return $this->accounts(liability: true);
    }

    /** @return Collection<int, TransactionCategory> */
    #[Computed]
    public function categories(): Collection
    {
        $kind = $this->intent === TransactionIntent::EXPENSE->value ? CategoryKind::EXPENSE : CategoryKind::INCOME;

        return TransactionCategory::query()
            ->where('user_id', $this->user()->id)
            ->where('kind', $kind->value)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /** @return list<TransactionIntent> */
    public function selectableIntents(): array
    {
        return IntentPostingService::selectableIntents();
    }

    public function render(): View
    {
        return view('livewire.quick-entry', ['intentEnum' => TransactionIntent::tryFrom($this->intent)]);
    }

    private function validateForm(): TransactionIntent
    {
        $today = CarbonImmutable::now((string) config('finance.display_timezone'))->toDateString();
        $intent = TransactionIntent::tryFrom($this->intent);

        $this->validate([
            'intent' => ['required', Rule::enum(TransactionIntent::class)->except([TransactionIntent::OPENING_BALANCE, TransactionIntent::BALANCE_ADJUSTMENT])],
            'amount' => ['required', 'string', 'max:32'],
            'transactionDate' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'description' => ['nullable', 'string', 'max:255'],
            'counterparty' => [$this->needsParty($intent) ? 'required_without:debtAccountId' : 'nullable', 'nullable', 'string', 'max:128'],
            'activity' => ['nullable', 'string', 'max:64'],
            'purpose' => ['nullable', 'string', 'max:128'],
            'categoryId' => [in_array($intent, [TransactionIntent::EXPENSE], true) || $intent?->isIncome() ? 'required' : 'nullable', 'nullable', 'string', 'ulid'],
            'sourceAccountId' => [$this->needsSource($intent) ? 'required' : 'nullable', 'nullable', 'string', 'ulid'],
            'destinationAccountId' => [$this->needsDestination($intent) ? 'required' : 'nullable', 'nullable', 'string', 'ulid', 'different:sourceAccountId'],
            'debtAccountId' => ['nullable', 'string', 'ulid'],
        ], [
            'counterparty.required_without' => 'Isi nama pihak atau pilih akun hutang.',
        ], [
            'intent' => 'jenis transaksi', 'amount' => 'nominal', 'transactionDate' => 'tanggal', 'counterparty' => 'pihak',
            'categoryId' => 'kategori', 'purpose' => 'keperluan', 'sourceAccountId' => 'akun sumber', 'destinationAccountId' => 'akun tujuan',
        ]);

        return $intent ?? throw new \LogicException('validated');
    }

    private function applyIntentDefaults(): void
    {
        $intent = TransactionIntent::tryFrom($this->intent);
        unset($this->categories);

        if ($intent !== null && isset(IntentPostingService::DEFAULT_CATEGORY[$intent->value]) && $this->categoryId === '') {
            $this->categoryId = (string) $this->categories->firstWhere('code', IntentPostingService::DEFAULT_CATEGORY[$intent->value])?->id;
        }

        $firstAsset = (string) $this->assetAccounts->first()?->id;

        $this->sourceAccountId = $this->needsSource($intent) ? ($this->sourceAccountId ?: $firstAsset) : '';
        $this->destinationAccountId = $this->needsDestination($intent) && $intent !== TransactionIntent::INTERNAL_TRANSFER
            ? ($this->destinationAccountId ?: $firstAsset)
            : ($intent === TransactionIntent::INTERNAL_TRANSFER ? $this->destinationAccountId : '');

        if (! in_array($intent, [TransactionIntent::LOAN_RECEIVED, TransactionIntent::DEBT_REPAYMENT], true)) {
            $this->debtAccountId = '';
        }
    }

    private function needsSource(?TransactionIntent $i): bool
    {
        return IntentPostingService::needsSource($i);
    }

    private function needsDestination(?TransactionIntent $i): bool
    {
        return IntentPostingService::needsDestination($i);
    }

    private function needsParty(?TransactionIntent $i): bool
    {
        return IntentPostingService::needsParty($i);
    }

    /** @return Collection<int, FinancialAccount> */
    private function accounts(bool $liability): Collection
    {
        return app(IntentPostingService::class)->accounts($this->user(), $liability);
    }

    private function resetForm(bool $keepText = false): void
    {
        $text = $this->text;
        $this->reset(['intent', 'amount', 'counterparty', 'activity', 'purpose', 'description', 'categoryId', 'sourceAccountId', 'destinationAccountId', 'debtAccountId', 'warnings', 'confidence']);
        $this->text = $keepText ? $text : '';
        $this->transactionDate = CarbonImmutable::now((string) config('finance.display_timezone'))->toDateString();
        $this->idempotencyKey = 'web-'.Str::uuid()->toString();
        $this->resetErrorBag();
    }

    private function successMessage(Transaction $t): string
    {
        $amount = Money::fromDatabase($t->getRawOriginal('amount'), $t->currency_code)->format();
        $party = $t->counterparty?->name;

        return sprintf('Tersimpan: %s %s%s.', $t->intent?->label() ?? $t->type->value, $amount, $party !== null ? ' — '.$party : '');
    }

    private function blankToNull(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
