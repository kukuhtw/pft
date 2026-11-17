<?php

declare(strict_types=1);

namespace App\Modules\Shared\Console;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Transactions\Models\IdempotencyKey;
use Illuminate\Console\Command;

/**
 * Data-retention housekeeping (ISO/IEC 27001 A.8.10). Never touches journals or transactions.
 */
final class PruneFinanceDataCommand extends Command
{
    protected $signature = 'finance:prune';

    protected $description = 'Delete expired idempotency keys and audit logs older than the configured retention period.';

    public function handle(): int
    {
        $keys = IdempotencyKey::query()->where('expires_at', '<', now())->delete();
        $this->info("Deleted {$keys} expired idempotency key(s).");

        $days = (int) config('finance.audit_retention_days');
        if ($days > 0) {
            $logs = AuditLog::query()->where('created_at', '<', now()->subDays($days))->delete();
            $this->info("Deleted {$logs} audit log(s) older than {$days} days.");
        }

        return self::SUCCESS;
    }
}
