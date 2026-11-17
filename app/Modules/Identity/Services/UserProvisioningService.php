<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Categories\Services\CategoryService;
use App\Modules\Identity\Models\UserPreference;
use App\Modules\Ledger\Enums\LedgerAccountClass;
use App\Modules\Ledger\Models\LedgerAccount;
use App\Modules\Ledger\Services\ChartOfAccountsService;
use Illuminate\Support\Facades\DB;

/**
 * Creates a user together with their preferences, system equity accounts and default categories.
 * Provisioning is idempotent so it can be re-run safely.
 */
final class UserProvisioningService
{
    public function __construct(
        private readonly ChartOfAccountsService $chart,
        private readonly CategoryService $categories,
        private readonly AuditLogger $audit,
    ) {}

    public function register(string $name, string $email, string $password): User
    {
        return DB::transaction(function () use ($name, $email, $password): User {
            $user = User::create(['name' => $name, 'email' => mb_strtolower(trim($email)), 'password' => $password]);
            $this->provision($user);
            $this->audit->record('user.registered', $user, $user);

            return $user;
        });
    }

    public function provision(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $currency = (string) config('finance.default_currency');

            UserPreference::query()->firstOrCreate(
                ['user_id' => $user->id],
                ['locale' => 'id', 'timezone' => (string) config('finance.display_timezone'), 'default_currency' => $currency],
            );

            $system = [
                ChartOfAccountsService::OPENING_BALANCE_EQUITY => 'Ekuitas Saldo Awal',
                ChartOfAccountsService::RECONCILIATION_EQUITY => 'Penyesuaian Rekonsiliasi',
            ];

            foreach ($system as $code => $name) {
                $exists = LedgerAccount::query()->where('user_id', $user->id)->where('code', $code)->exists();
                if (! $exists) {
                    $this->chart->createAccount($user, LedgerAccountClass::EQUITY, $name, $currency, (string) $code, true);
                }
            }

            $this->categories->provisionDefaults($user, $currency);
        });
    }
}
