<?php

declare(strict_types=1);

namespace App\Modules\Categories\Models;

use App\Modules\Categories\Enums\CategoryKind;
use App\Modules\Ledger\Models\LedgerAccount;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Income/expense category backed 1:1 by an INCOME/EXPENSE ledger account.
 *
 * @property string $id
 * @property int $user_id
 * @property string $ledger_account_id
 * @property string|null $parent_id
 * @property string $code
 * @property string $name
 * @property CategoryKind $kind
 * @property bool $is_system
 * @property bool $is_active
 */
class TransactionCategory extends Model
{
    use HasUlids;

    protected $fillable = ['user_id', 'ledger_account_id', 'parent_id', 'code', 'name', 'kind', 'is_system', 'is_active'];

    protected function casts(): array
    {
        return [
            'kind' => CategoryKind::class,
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
