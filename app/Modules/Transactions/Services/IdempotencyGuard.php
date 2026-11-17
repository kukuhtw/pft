<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Services;

use App\Models\User;
use App\Modules\Transactions\Exceptions\IdempotencyConflictException;
use App\Modules\Transactions\Models\IdempotencyKey;
use App\Modules\Transactions\Models\Transaction;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Ensures a client-supplied idempotency key produces at most one posted transaction.
 *
 * The key row is inserted in the same DB transaction as the posting, so either both commit or neither does.
 * A concurrent request with the same key blocks on the unique index until the first commits, then replays.
 */
final class IdempotencyGuard
{
    /** Top-level transactions are retried on deadlock / lock-wait errors (nested ones are not). */
    private const int DEADLOCK_RETRIES = 3;

    /**
     * @param  array<string, mixed>  $fingerprint
     * @param  Closure(): Transaction  $work
     */
    public function run(User $user, string $scope, ?string $key, array $fingerprint, Closure $work): Transaction
    {
        if ($key === null) {
            return DB::transaction($work, self::DEADLOCK_RETRIES);
        }

        $hash = hash('sha256', (string) json_encode([$scope, $fingerprint], JSON_THROW_ON_ERROR));

        if (($existing = $this->find($user, $key)) !== null) {
            return $this->replay($existing, $scope, $hash);
        }

        try {
            return DB::transaction(function () use ($user, $scope, $key, $hash, $work): Transaction {
                $record = IdempotencyKey::create([
                    'user_id' => $user->id,
                    'idempotency_key' => $key,
                    'scope' => $scope,
                    'request_hash' => $hash,
                    'expires_at' => now()->addHours((int) config('finance.idempotency_ttl_hours', 72)),
                ]);

                $transaction = $work();
                $record->update(['transaction_id' => $transaction->id]);

                return $transaction;
            }, self::DEADLOCK_RETRIES);
        } catch (UniqueConstraintViolationException $e) {
            $existing = $this->find($user, $key);

            if ($existing === null) {
                throw $e; // violation came from somewhere else
            }

            return $this->replay($existing, $scope, $hash);
        }
    }

    private function find(User $user, string $key): ?IdempotencyKey
    {
        return IdempotencyKey::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', $key)
            ->first();
    }

    private function replay(IdempotencyKey $existing, string $scope, string $hash): Transaction
    {
        if ($existing->scope !== $scope || ! hash_equals($existing->request_hash, $hash)) {
            throw new IdempotencyConflictException(__('ledger.idempotency_conflict'));
        }

        if ($existing->transaction_id === null) {
            throw new IdempotencyConflictException(__('ledger.idempotency_in_progress'));
        }

        return Transaction::query()
            ->where('user_id', $existing->user_id)
            ->findOrFail($existing->transaction_id);
    }
}
