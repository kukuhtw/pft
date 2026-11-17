<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $user_id
 * @property string $locale
 * @property string $timezone
 * @property string $default_currency
 */
class UserPreference extends Model
{
    protected $fillable = ['user_id', 'locale', 'timezone', 'default_currency'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
