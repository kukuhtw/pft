<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Models;

use App\Models\User;
use App\Modules\Accounts\Models\FinancialAccount;
use App\Modules\Categories\Models\Merchant;
use App\Modules\Categories\Models\TransactionCategory;
use App\Modules\Counterparties\Models\Counterparty;
use App\Modules\Interpretation\Enums\TransactionIntent;
use App\Modules\Ledger\Exceptions\ImmutableRecordException;
use App\Modules\Ledger\Models\JournalEntry;
use App\Modules\Shared\Casts\DateOnly;
use App\Modules\Transactions\Enums\Channel;
use App\Modules\Transactions\Enums\PaymentMethod;
use App\Modules\Transactions\Enums\TransactionStatus;
use App\Modules\Transactions\Enums\TransactionType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Business-level record of a financial event. Its accounting effect lives in journal_entries.
 * Once POSTED, financial fields are frozen; only the reversal workflow may change the status.
 *
 * @property string $id
 * @property int $user_id
 * @property TransactionType $type
 * @property TransactionIntent|null $intent
 * @property TransactionStatus $status
 * @property Carbon $transaction_date
 * @property string $amount
 * @property string $currency_code
 * @property string|null $source_account_id
 * @property string|null $destination_account_id
 * @property string|null $category_id
 * @property string|null $merchant_id
 * @property string|null $counterparty_id
 * @property string|null $activity
 * @property string|null $purpose what a loan was taken for (LOAN_RECEIVED)
 * @property string|null $description
 * @property string|null $reference_number
 * @property PaymentMethod|null $payment_method
 * @property Channel $channel
 * @property array<string, mixed>|null $metadata
 * @property int $created_by
 * @property Carbon|null $posted_at
 * @property Carbon|null $reversed_at
 * @property int|null $reversed_by
 * @property string|null $reversal_reason
 */
class Transaction extends Model
{
    use HasUlids;

    /** Columns the reversal workflow may change after posting. */
    private const array MUTABLE_AFTER_POSTING = ['status', 'reversed_at', 'reversed_by', 'reversal_reason', 'updated_at'];

    protected $fillable = [
        'user_id', 'type', 'intent', 'status', 'transaction_date', 'amount', 'currency_code', 'source_account_id',
        'destination_account_id', 'category_id', 'merchant_id', 'counterparty_id', 'activity', 'purpose', 'description', 'reference_number',
        'payment_method', 'channel', 'metadata', 'created_by', 'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'intent' => TransactionIntent::class,
            'status' => TransactionStatus::class,
            'payment_method' => PaymentMethod::class,
            'channel' => Channel::class,
            'transaction_date' => DateOnly::class,
            'amount' => 'decimal:2',
            'metadata' => 'array',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (Transaction $transaction): void {
            $original = TransactionStatus::from((string) $transaction->getRawOriginal('status'));

            if (! $original->isFinal()) {
                return;
            }

            $illegal = array_diff(array_keys($transaction->getDirty()), self::MUTABLE_AFTER_POSTING);

            if ($original === TransactionStatus::REVERSED || $illegal !== []) {
                throw new ImmutableRecordException('Transaction');
            }
        });

        static::deleting(static function (Transaction $transaction): void {
            if (TransactionStatus::from((string) $transaction->getRawOriginal('status'))->isFinal()) {
                throw new ImmutableRecordException('Transaction');
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'source_account_id');
    }

    public function destinationAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'destination_account_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TransactionCategory::class, 'category_id');
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class);
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class)->orderBy('posted_at')->orderBy('id');
    }
}
