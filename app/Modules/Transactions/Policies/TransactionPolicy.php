<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Policies;

use App\Models\User;
use App\Modules\Transactions\Models\Transaction;
use Illuminate\Auth\Access\Response;

final class TransactionPolicy
{
    /** Other tenants' transactions are reported as 404 so their existence is not disclosed. */
    public function view(User $user, Transaction $transaction): Response
    {
        return (int) $transaction->user_id === $user->id ? Response::allow() : Response::denyAsNotFound();
    }

    public function reverse(User $user, Transaction $transaction): Response
    {
        return $this->view($user, $transaction);
    }
}
