<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * No default accounts with well-known passwords (ISO/IEC 27001 A.5.17).
     * The dashboard login comes from DASHBOARD_ADMIN_* in .env via `php artisan dashboard:user`.
     */
    public function run(): void
    {
        if ((string) config('access.admin.email') === '' || (string) config('access.admin.password') === '') {
            $this->command?->warn('No user seeded: set DASHBOARD_ADMIN_EMAIL and DASHBOARD_ADMIN_PASSWORD, then run php artisan dashboard:user.');

            return;
        }

        $this->command?->call('dashboard:user');
    }
}
