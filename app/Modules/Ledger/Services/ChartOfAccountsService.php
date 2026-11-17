<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Services;

use App\Models\User;
use App\Modules\Ledger\Enums\LedgerAccountClass;
use App\Modules\Ledger\Enums\LedgerAccountStatus;
use App\Modules\Ledger\Models\LedgerAccount;
use Illuminate\Support\Facades\DB;

/**
 * Manages the per-user chart of accounts. Ledger accounts start with a zero balance;
 * balances only ever change through LedgerPostingService.
 */
final class ChartOfAccountsService
{
    public const string OPENING_BALANCE_EQUITY = '3000';

    public const string RECONCILIATION_EQUITY = '3100';

    public function createAccount(
        User $user,
        LedgerAccountClass $class,
        string $name,
        string $currency = 'IDR',
        ?string $code = null,
        bool $isSystem = false,
    ): LedgerAccount {
        return DB::transaction(function () use ($user, $class, $name, $currency, $code, $isSystem): LedgerAccount {
            // Serialise code allocation per tenant.
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            return LedgerAccount::create([
                'user_id' => $user->id,
                'code' => $code ?? $this->nextCode($user, $class),
                'name' => $name,
                'class' => $class,
                'currency_code' => $currency,
                'is_system' => $isSystem,
                'status' => LedgerAccountStatus::ACTIVE,
            ]);
        });
    }

    public function systemAccount(User $user, string $code): LedgerAccount
    {
        return LedgerAccount::query()
            ->where('user_id', $user->id)
            ->where('code', $code)
            ->where('is_system', true)
            ->firstOrFail();
    }

    /**
     * Auto-numbered codes: 1001, 1002… for assets; 2001… liabilities; 4901… income; 5901… expense.
     * System codes (x000–x899 for income/expense, x000–x999 for equity) are allocated explicitly.
     */
    private function nextCode(User $user, LedgerAccountClass $class): string
    {
        $prefix = $class->codePrefix();
        $floor = in_array($class, [LedgerAccountClass::INCOME, LedgerAccountClass::EXPENSE], true) ? 900 : 0;

        $codes = LedgerAccount::query()
            ->where('user_id', $user->id)
            ->where('class', $class)
            ->where('code', 'like', $prefix.'%')
            ->pluck('code');

        $max = $floor;
        foreach ($codes as $existing) {
            $seq = (int) substr((string) $existing, 1);
            if ($seq > $max && $seq >= $floor) {
                $max = $seq;
            }
        }

        $next = $max + 1;

        return $prefix.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }
}
