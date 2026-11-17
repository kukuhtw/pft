<?php

declare(strict_types=1);

namespace App\Modules\Counterparties\Models;

use App\Modules\Accounts\Models\FinancialAccount;
use App\Modules\Counterparties\Enums\CounterpartyKind;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The other party of a transaction: employer, lender, friend, client, shop owner…
 * Tenant-private. Only a display name and aliases are kept (data minimisation).
 *
 * @property string $id
 * @property int $user_id
 * @property string $name
 * @property string $normalized_name
 * @property CounterpartyKind $kind
 * @property string|null $aliases
 */
class Counterparty extends Model
{
    use HasUlids;

    protected $fillable = ['user_id', 'name', 'normalized_name', 'kind', 'aliases'];

    protected function casts(): array
    {
        return ['kind' => CounterpartyKind::class];
    }

    /** Liability accounts (loans, cards) owed to this party. */
    public function debtAccounts(): HasMany
    {
        return $this->hasMany(FinancialAccount::class);
    }
}
