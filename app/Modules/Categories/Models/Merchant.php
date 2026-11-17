<?php

declare(strict_types=1);

namespace App\Modules\Categories\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int $user_id
 * @property string $name
 * @property string $normalized_name
 * @property string|null $default_category_id
 */
class Merchant extends Model
{
    use HasUlids;

    protected $fillable = ['user_id', 'name', 'normalized_name', 'default_category_id'];

    public function defaultCategory(): BelongsTo
    {
        return $this->belongsTo(TransactionCategory::class, 'default_category_id');
    }
}
