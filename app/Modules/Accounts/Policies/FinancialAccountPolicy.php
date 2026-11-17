<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Policies;

use App\Models\User;
use App\Modules\Accounts\Models\FinancialAccount;
use Illuminate\Auth\Access\Response;

final class FinancialAccountPolicy
{
    /** Other tenants' accounts are reported as 404 so their existence is not disclosed. */
    public function view(User $user, FinancialAccount $account): Response
    {
        return (int) $account->user_id === $user->id ? Response::allow() : Response::denyAsNotFound();
    }
}
