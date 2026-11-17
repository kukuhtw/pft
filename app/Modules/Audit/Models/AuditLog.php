<?php

declare(strict_types=1);

namespace App\Modules\Audit\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit trail. Never store secrets, tokens, passwords or full account numbers in `properties`.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $actor_id
 * @property string $event
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<string, mixed>|null $properties
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'actor_id', 'event', 'subject_type', 'subject_id', 'properties', 'ip_address', 'user_agent'];

    protected function casts(): array
    {
        return ['properties' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(static fn (): bool => false);
    }
}
