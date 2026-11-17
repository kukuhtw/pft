<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Models;

use App\Modules\Ledger\Enums\EntryDirection;
use App\Modules\Ledger\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $journal_entry_id
 * @property int $user_id
 * @property string $ledger_account_id
 * @property int $line_no
 * @property EntryDirection $direction
 * @property string $amount
 * @property string|null $memo
 */
class JournalLine extends Model
{
    use Immutable;

    public const UPDATED_AT = null;

    protected $fillable = ['journal_entry_id', 'user_id', 'ledger_account_id', 'line_no', 'direction', 'amount', 'memo'];

    protected function casts(): array
    {
        return [
            'direction' => EntryDirection::class,
            'amount' => 'decimal:2',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }
}
