<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use App\Modules\Accounts\Models\AccountType;
use App\Modules\Accounts\Models\FinancialAccount;
use App\Modules\Accounts\Models\FinancialInstitution;
use App\Modules\Accounts\Services\FinancialAccountService;
use App\Modules\Counterparties\Services\CounterpartyService;
use App\Modules\Ledger\Enums\LedgerAccountClass;
use App\Modules\Shared\Domain\Money;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Shared\Support\MoneyInput;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Akun')]
final class Accounts extends Component
{
    public string $name = '';

    public string $accountType = 'BANK';

    public string $institutionCode = '';

    public string $openingBalance = '';

    public string $counterparty = '';

    public string $last4 = '';

    public ?string $flash = null;

    /** Account being edited (owner re-checked on every action). */
    public ?string $editingId = null;

    public string $editName = '';

    public string $editLast4 = '';

    public string $editInstitutionCode = '';

    /** Liabilities only: name of the party the debt is owed to. */
    public string $editCounterparty = '';

    /** none | opening | today */
    public string $balanceMode = 'none';

    public string $targetBalance = '';

    public function edit(string $id): void
    {
        $account = $this->ownedAccount($id);
        $this->resetErrorBag();
        $this->flash = null;
        $this->editingId = $account->id;
        $this->editName = $account->name;
        $this->editLast4 = (string) $account->account_number_last4;
        $this->editInstitutionCode = (string) $account->institution?->code;
        $this->editCounterparty = (string) $account->counterparty?->name;
        $this->balanceMode = 'none';
        $this->targetBalance = '';
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingId', 'editName', 'editLast4', 'editInstitutionCode', 'editCounterparty', 'balanceMode', 'targetBalance']);
        $this->resetErrorBag();
    }

    public function saveEdit(FinancialAccountService $accounts, CounterpartyService $counterparties): void
    {
        $user = $this->user();
        $account = $this->ownedAccount((string) $this->editingId);

        $this->validate([
            'editName' => ['required', 'string', 'max:128', Rule::unique('financial_accounts', 'name')->where('user_id', $user->id)->ignore($account->id)],
            'editLast4' => ['nullable', 'regex:/^\d{4}$/'],
            'editInstitutionCode' => ['nullable', 'string', Rule::exists('financial_institutions', 'code')->where('is_active', true)],
            'editCounterparty' => ['nullable', 'string', 'max:128'],
            'balanceMode' => ['required', Rule::in(['none', 'opening', 'today'])],
            'targetBalance' => [$this->balanceMode === 'none' ? 'nullable' : 'required', 'nullable', 'string', 'max:32'],
        ], [], ['editName' => 'nama akun', 'editLast4' => '4 digit terakhir', 'editInstitutionCode' => 'bank / e-wallet', 'editCounterparty' => 'pihak', 'targetBalance' => 'saldo']);

        $target = null;
        if ($this->balanceMode !== 'none') {
            $target = MoneyInput::parse($this->targetBalance, $account->currency_code);
            // MoneyInput only accepts positive amounts; a balance may legitimately be zero (debt paid off, empty wallet).
            if ($target === null && preg_match('/^(rp\.?\s*)?0+([.,]0+)?$/i', trim($this->targetBalance)) === 1) {
                $target = Money::zero($account->currency_code);
            }
            if ($target === null || $target->isNegative()) {
                $this->addError('targetBalance', 'Nominal tidak valid. Contoh: 0, 50000, 1.500.000, 1,5jt.');

                return;
            }
        }

        $messages = [];
        try {
            DB::transaction(function () use ($accounts, $counterparties, $user, $account, $target, &$messages): void {
                $newName = trim($this->editName);
                $newLast4 = $this->editLast4 !== '' ? $this->editLast4 : null;
                $newInstitution = $this->editInstitutionCode !== '' ? $this->editInstitutionCode : null;
                $newCounterpartyId = $account->isLiability() ? $counterparties->resolve($user, $this->editCounterparty)?->id : null;

                if ($newName !== $account->name || $newLast4 !== $account->account_number_last4
                    || $newInstitution !== $account->institution?->code || $newCounterpartyId !== $account->counterparty_id) {
                    $accounts->updateDetails($user, $account, $newName, $newLast4, $newInstitution, $newCounterpartyId);
                    $messages[] = 'detail akun disimpan';
                }

                if ($target !== null) {
                    $posted = $this->balanceMode === 'opening'
                        ? $accounts->correctOpeningBalance($user, $account, $target)
                        : $accounts->adjustBalanceToday($user, $account, $target);
                    $messages[] = $posted === null
                        ? 'saldo sudah sesuai, tidak ada yang dicatat'
                        : ($this->balanceMode === 'opening' ? 'saldo awal dikoreksi' : 'saldo disesuaikan').' ('.Money::fromDatabase($posted->getRawOriginal('amount'), $posted->currency_code)->format().')';
                }
            });
        } catch (DomainException $e) {
            $this->addError('saveEdit', $e->getMessage());

            return;
        }

        $this->flash = 'Akun "'.trim($this->editName).'": '.($messages === [] ? 'tidak ada perubahan' : implode(', ', $messages)).'.';
        $this->cancelEdit();
    }

    public function create(FinancialAccountService $accounts, CounterpartyService $counterparties): void
    {
        $user = $this->user();
        $this->validate([
            'name' => ['required', 'string', 'max:128', Rule::unique('financial_accounts', 'name')->where('user_id', $user->id)],
            'accountType' => ['required', 'string', Rule::exists('account_types', 'code')->where('is_active', true)],
            'institutionCode' => ['nullable', 'string', Rule::exists('financial_institutions', 'code')->where('is_active', true)],
            'openingBalance' => ['nullable', 'string', 'max:32'],
            'counterparty' => ['nullable', 'string', 'max:128'],
            // Data minimisation: never a full account/card number.
            'last4' => ['nullable', 'regex:/^\d{4}$/'],
        ], [], ['name' => 'nama akun', 'accountType' => 'jenis akun', 'last4' => '4 digit terakhir']);

        $currency = (string) config('finance.default_currency');
        $opening = trim($this->openingBalance) === '' ? Money::zero($currency) : MoneyInput::parse($this->openingBalance, $currency);
        if ($opening === null) {
            $this->addError('openingBalance', 'Saldo awal tidak valid.');

            return;
        }

        $isLiability = $this->selectedTypeIsLiability();

        try {
            DB::transaction(fn () => $accounts->open(
                user: $user,
                name: trim($this->name),
                accountTypeCode: $this->accountType,
                institutionCode: $this->institutionCode !== '' ? $this->institutionCode : null,
                openingBalance: $opening,
                accountNumberLast4: $this->last4 !== '' ? $this->last4 : null,
                counterpartyId: $isLiability ? $counterparties->resolve($user, $this->counterparty)?->id : null,
            ));
        } catch (DomainException $e) {
            $this->addError('create', $e->getMessage());

            return;
        }

        $this->flash = 'Akun "'.trim($this->name).'" dibuat.';
        $this->reset(['name', 'institutionCode', 'openingBalance', 'counterparty', 'last4']);
    }

    public function render(): View
    {
        return view('livewire.accounts', [
            'accounts' => FinancialAccount::query()->with(['ledgerAccount', 'accountType', 'institution', 'counterparty'])
                ->where('user_id', $this->user()->id)->orderBy('name')->get(),
            'editing' => $this->editingId !== null
                ? FinancialAccount::query()->with('ledgerAccount')->where('user_id', $this->user()->id)->find($this->editingId)
                : null,
            'types' => AccountType::query()->where('is_active', true)->orderBy('name')->get(),
            'institutions' => FinancialInstitution::query()->where('is_active', true)->orderBy('name')->get(),
            'isLiability' => $this->selectedTypeIsLiability(),
        ]);
    }

    private function selectedTypeIsLiability(): bool
    {
        return AccountType::query()->find($this->accountType)?->ledger_class === LedgerAccountClass::LIABILITY;
    }

    /** Owner-scoped lookup: another user's account id behaves exactly like a missing one. */
    private function ownedAccount(string $id): FinancialAccount
    {
        return FinancialAccount::query()->with(['ledgerAccount', 'institution', 'counterparty'])->where('user_id', $this->user()->id)->findOrFail($id);
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
