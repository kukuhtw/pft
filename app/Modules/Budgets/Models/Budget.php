<?php

declare(strict_types=1);

namespace App\Modules\Budgets\Models;

use App\Modules\Categories\Models\TransactionCategory;
use App\Modules\Shared\Domain\Money;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int $user_id
 * @property string $category_id
 * @property string $period YYYY-MM
 * @property string $amount
 * @property string $currency_code
 * @property-read TransactionCategory $category
 */
class Budget extends Model
{
    use HasUlids;

    protected $fillable = ['user_id', 'category_id', 'period', 'amount', 'currency_code'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TransactionCategory::class, 'category_id');
    }

    public function money(): Money
    {
        return Money::fromDatabase($this->getRawOriginal('amount'), $this->currency_code);
    }
}
