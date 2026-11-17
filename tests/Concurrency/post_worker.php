<?php

declare(strict_types=1);

/**
 * Test-only worker: boots the app in a separate OS process and posts one transaction.
 * Usage: php post_worker.php <expense|income> <userId> <accountId> <categoryId> <amount> <startAtUnix> [idempotencyKey]
 * Exit codes: 0 = posted/replayed, 3 = insufficient funds, 1 = other error.
 */

use App\Models\User;
use App\Modules\Shared\Domain\Money;
use App\Modules\Transactions\Data\TransactionInput;
use App\Modules\Transactions\Exceptions\InsufficientFundsException;
use App\Modules\Transactions\Services\TransactionService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $kind, $userId, $accountId, $categoryId, $amount, $startAt] = $argv;
$key = $argv[7] ?? null;

// Start barrier: every worker begins posting at (nearly) the same instant.
while (microtime(true) < (float) $startAt) {
    usleep(500);
}

try {
    $user = User::query()->findOrFail((int) $userId);
    $service = $app->make(TransactionService::class);

    $tx = $kind === 'income'
        ? $service->recordIncome($user, new TransactionInput(amount: Money::of($amount), destinationAccountId: $accountId, categoryId: $categoryId, idempotencyKey: $key))
        : $service->recordExpense($user, new TransactionInput(amount: Money::of($amount), sourceAccountId: $accountId, categoryId: $categoryId, idempotencyKey: $key));

    echo 'OK '.$tx->id;
    exit(0);
} catch (InsufficientFundsException) {
    echo 'INSUFFICIENT';
    exit(3);
} catch (Throwable $e) {
    fwrite(STDERR, $e::class.': '.$e->getMessage());
    exit(1);
}
