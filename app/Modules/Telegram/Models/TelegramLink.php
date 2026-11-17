<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $user_id
 * @property int $chat_id
 * @property Carbon $linked_at
 * @property Carbon|null $last_used_at
 * @property-read User $user
 */
class TelegramLink extends Model
{
    use HasUlids;

    protected $fillable = ['user_id', 'chat_id', 'linked_at', 'last_used_at'];

    protected function casts(): array
    {
        return ['chat_id' => 'integer', 'linked_at' => 'datetime', 'last_used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
