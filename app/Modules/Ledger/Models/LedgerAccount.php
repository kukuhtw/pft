<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Models;

use App\Models\User;
use App\Modules\Ledger\Enums\LedgerAccountClass;
use App\Modules\Ledger\Enums\LedgerAccountStatus;
use App\Modules\Shared\Domain\Money;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A chart-of-accounts entry. `balance` is a projection maintained exclusively by
 * LedgerPostingService and verified by LedgerIntegrityVerifier.
 *
 * @property string $id
 * @property int $user_id
 * @property string $code
 * @property string $name
 * @property LedgerAccountClass $class
 * @property string $currency_code
 * @property string $balance
 * @property bool $is_system
 * @property LedgerAccountStatus $status
 */
class LedgerAccount extends Model
{
    use HasUlids;

    protected $fillable = ['user_id', 'code', 'name', 'class', 'currency_code', 'is_system', 'status'];

    // `balance` deliberately NOT fillable: it can only change through the posting service.

    protected function casts(): array
    {
        return [
            'class' => LedgerAccountClass::class,
            'status' => LedgerAccountStatus::class,
            'balance' => 'decimal:2',
            'is_system' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function balanceMoney(): Money
    {
        return Money::fromDatabase($this->getRawOriginal('balance'), $this->currency_code);
    }
}
