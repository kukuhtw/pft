<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Models\User;
use App\Modules\Accounts\Enums\AccountStatus;
use App\Modules\Ledger\Enums\LedgerAccountClass;
use App\Modules\Ledger\Models\LedgerAccount;
use App\Modules\Shared\Casts\DateOnly;
use App\Modules\Shared\Domain\Money;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user-facing money container (bank account, wallet, card, loan) backed 1:1 by a ledger account.
 * Its balance is read from the ledger projection; it has no writable balance of its own.
 *
 * @property string $id
 * @property int $user_id
 * @property string $ledger_account_id
 * @property string $account_type_code
 * @property string|null $financial_institution_id
 * @property string $name
 * @property string $currency_code
 * @property string|null $account_number_last4
 * @property string $opening_balance
 * @property bool $allow_negative_balance
 * @property AccountStatus $status
 * @property string|null $aliases
 * @property-read LedgerAccount $ledgerAccount
 * @property-read AccountType $accountType
 */
class FinancialAccount extends Model
{
    use HasUlids;

    protected $fillable = [
        'user_id', 'ledger_account_id', 'account_type_code', 'financial_institution_id', 'name', 'currency_code',
        'account_number_last4', 'opening_balance', 'opening_balance_date', 'allow_negative_balance', 'status', 'aliases',
    ];

    protected function casts(): array
    {
        return [
            'status' => AccountStatus::class,
            'opening_balance' => 'decimal:2',
            'opening_balance_date' => DateOnly::class,
            'allow_negative_balance' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    public function accountType(): BelongsTo
    {
        return $this->belongsTo(AccountType::class, 'account_type_code', 'code');
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(FinancialInstitution::class, 'financial_institution_id');
    }

    public function isLiability(): bool
    {
        return $this->ledgerAccount->class === LedgerAccountClass::LIABILITY;
    }

    public function isActive(): bool
    {
        return $this->status === AccountStatus::ACTIVE;
    }

    /** Current balance from the verified ledger projection (normal-balance sign). */
    public function balance(): Money
    {
        return $this->ledgerAccount->balanceMoney();
    }
}
