<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Console;

use App\Models\User;
use App\Modules\Ledger\Services\LedgerIntegrityVerifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class VerifyLedgerCommand extends Command
{
    protected $signature = 'ledger:verify {--user= : Public ID (ULID) of a single user to verify}';

    protected $description = 'Verify double-entry integrity: balanced journals, balance projections, tenant isolation, transaction/journal linkage.';

    public function handle(LedgerIntegrityVerifier $verifier): int
    {
        $userId = null;

        if (($publicId = $this->option('user')) !== null) {
            $userId = User::query()->where('public_id', $publicId)->value('id');

            if ($userId === null) {
                $this->error('User not found.');

                return self::FAILURE;
            }
        }

        $issues = $verifier->verify($userId !== null ? (int) $userId : null);

        if ($issues === []) {
            $this->info('Ledger integrity OK.');

            return self::SUCCESS;
        }

        // Issue payloads contain only internal identifiers and amounts (no personal data).
        Log::critical('Ledger integrity verification failed', ['issue_count' => count($issues), 'issues' => array_slice($issues, 0, 50)]);

        $this->error(count($issues).' integrity issue(s) found:');
        foreach ($issues as $issue) {
            $this->line(' - '.json_encode($issue, JSON_UNESCAPED_SLASHES));
        }

        return self::FAILURE;
    }
}
