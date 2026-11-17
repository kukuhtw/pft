<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $idempotency_key
 * @property string $scope
 * @property string $request_hash
 * @property string|null $transaction_id
 * @property Carbon $expires_at
 */
class IdempotencyKey extends Model
{
    protected $fillable = ['user_id', 'idempotency_key', 'scope', 'request_hash', 'transaction_id', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
