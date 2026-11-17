<?php

declare(strict_types=1);

namespace App\Modules\Identity\Console;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Services\AccessPolicy;
use App\Modules\Identity\Services\UserProvisioningService;
use App\Modules\Telegram\Services\TelegramLinkService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Creates or updates the dashboard account from DASHBOARD_ADMIN_* in .env.
 * The database keeps only a bcrypt hash; login never reads .env.
 *
 *   php artisan dashboard:user          sync the account from .env
 *   php artisan dashboard:user --hash   ask for a password and print its bcrypt hash for .env
 */
final class DashboardUserCommand extends Command
{
    protected $signature = 'dashboard:user {--hash : Prompt for a password and print a bcrypt hash to put in DASHBOARD_ADMIN_PASSWORD}';

    protected $description = 'Create or update the dashboard login from DASHBOARD_ADMIN_EMAIL / DASHBOARD_ADMIN_PASSWORD';

    public function handle(UserProvisioningService $provisioning, AuditLogger $audit, TelegramLinkService $telegram): int
    {
        if ($this->option('hash')) {
            return $this->printHash();
        }

        $name = trim((string) config('access.admin.name')) ?: 'Admin';
        $email = mb_strtolower(trim((string) config('access.admin.email')));
        $secret = (string) config('access.admin.password');

        if ($email === '' || $secret === '') {
            $this->error('Set DASHBOARD_ADMIN_EMAIL and DASHBOARD_ADMIN_PASSWORD in .env first.');

            return self::FAILURE;
        }

        if (Validator::make(['email' => $email], ['email' => ['email:rfc', 'max:255']])->fails()) {
            $this->error('DASHBOARD_ADMIN_EMAIL is not a valid email address.');

            return self::FAILURE;
        }

        $isHash = $this->isBcrypt($secret);
        if (! $isHash) {
            $check = Validator::make(['password' => $secret], ['password' => [Password::defaults()]]);
            if ($check->fails()) {
                $this->error('DASHBOARD_ADMIN_PASSWORD is too weak: '.implode(' ', $check->errors()->all()));

                return self::FAILURE;
            }
        }

        if (! AccessPolicy::emailAllowed($email)) {
            $this->warn('Note: '.$email.' is not in AUTH_ALLOWED_EMAILS, so it will not be able to sign in.');
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $user = $provisioning->register($name, $email, $this->randomPassword());
            $this->setPassword($user, $secret, $isHash);
            $audit->record('auth.dashboard_user_created', $user, $user, ['channel' => 'CLI']);
            $this->info("Dashboard user created: {$email}");
        } else {
            $changed = $isHash ? ! hash_equals($user->password, $secret) : ! Hash::check($secret, $user->password);

            if ($user->name !== $name) {
                $user->forceFill(['name' => $name])->save();
            }

            if (! $changed) {
                $this->info("Dashboard user {$email} is already up to date.");

                return $this->finish($isHash);
            }

            // Same effect as changing the password in Profil: every old session and token stops working.
            DB::transaction(function () use ($user, $secret, $isHash): void {
                $this->setPassword($user, $secret, $isHash);
                $user->tokens()->delete();
                if (config('session.driver') === 'database') {
                    DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
                }
            });
            $telegram->unlink($user, 'password_changed');
            $audit->record('auth.password_changed', $user, $user, ['channel' => 'CLI']);
            $this->info("Password updated for {$email}. Other sessions, API tokens and the Telegram link were revoked.");
        }

        return $this->finish($isHash);
    }

    private function finish(bool $isHash): int
    {
        if (! $isHash) {
            $this->warn('DASHBOARD_ADMIN_PASSWORD is plain text in .env: keep that file readable only by the app (chmod 600) and out of Git/backups shared with others.');
        }

        return self::SUCCESS;
    }

    private function printHash(): int
    {
        $password = (string) $this->secret('New dashboard password');
        $confirm = (string) $this->secret('Repeat the password');

        if ($password !== $confirm) {
            $this->error('Passwords do not match.');

            return self::FAILURE;
        }

        $check = Validator::make(['password' => $password], ['password' => [Password::defaults()]]);
        if ($check->fails()) {
            $this->error(implode(' ', $check->errors()->all()));

            return self::FAILURE;
        }

        $this->line('Put this in .env (single quotes keep the $ signs intact):');
        $this->line("DASHBOARD_ADMIN_PASSWORD='".Hash::make($password)."'");

        return self::SUCCESS;
    }

    private function setPassword(User $user, string $secret, bool $isHash): void
    {
        // A ready-made bcrypt hash is stored as-is (bypassing the "hashed" cast, which would reject it).
        DB::table('users')->where('id', $user->id)->update([
            'password' => $isHash ? $secret : Hash::make($secret),
            'updated_at' => now(),
        ]);
        $user->refresh();
    }

    private function isBcrypt(string $value): bool
    {
        return preg_match('/^\$2[aby]\$\d{2}\$[.\/A-Za-z0-9]{53}$/', $value) === 1;
    }

    private function randomPassword(): string
    {
        return bin2hex(random_bytes(24)).'Aa1';
    }
}
