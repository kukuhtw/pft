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
            'accounts' => FinancialAccount::query()->with(['ledgerAccount', 'institution', 'counterparty'])
                ->where('user_id', $this->user()->id)->orderBy('name')->get(),
            'types' => AccountType::query()->where('is_active', true)->orderBy('name')->get(),
            'institutions' => FinancialInstitution::query()->where('is_active', true)->orderBy('name')->get(),
            'isLiability' => $this->selectedTypeIsLiability(),
        ]);
    }

    private function selectedTypeIsLiability(): bool
    {
        return AccountType::query()->find($this->accountType)?->ledger_class === LedgerAccountClass::LIABILITY;
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
