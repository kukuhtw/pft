<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Daily double-entry integrity check; exits non-zero and logs CRITICAL on any discrepancy.
Schedule::command('ledger:verify')->dailyAt('02:00')->timezone('Asia/Jakarta')->withoutOverlapping()->onOneServer();

// Data-retention housekeeping (expired idempotency keys, aged audit logs).
Schedule::command('finance:prune')->dailyAt('03:00')->timezone('Asia/Jakarta')->withoutOverlapping()->onOneServer();
