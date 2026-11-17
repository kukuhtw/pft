<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Models;

use App\Models\User;
use App\Modules\Ledger\Enums\JournalEntryType;
use App\Modules\Ledger\Models\Concerns\Immutable;
use App\Modules\Shared\Casts\DateOnly;
use App\Modules\Transactions\Models\Transaction;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $user_id
 * @property string|null $transaction_id
 * @property JournalEntryType $entry_type
 * @property string|null $reverses_entry_id
 * @property Carbon $entry_date
 * @property string $currency_code
 * @property string $total_debit
 * @property string $total_credit
 * @property int $posted_by
 * @property Carbon $posted_at
 */
class JournalEntry extends Model
{
    use HasUlids;
    use Immutable;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'transaction_id', 'entry_type', 'reverses_entry_id', 'entry_date', 'description',
        'currency_code', 'total_debit', 'total_credit', 'posted_by', 'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'entry_type' => JournalEntryType::class,
            'entry_date' => DateOnly::class,
            'total_debit' => 'decimal:2',
            'total_credit' => 'decimal:2',
            'posted_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('line_no');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_entry_id');
    }
}
