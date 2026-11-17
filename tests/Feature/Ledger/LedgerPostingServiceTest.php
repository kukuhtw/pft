<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Modules\Ledger\Data\JournalDraft;
use App\Modules\Ledger\Data\JournalLineDraft;
use App\Modules\Ledger\Exceptions\InvalidJournalException;
use App\Modules\Ledger\Exceptions\LedgerAccountNotUsableException;
use App\Modules\Ledger\Exceptions\UnbalancedJournalException;
use App\Modules\Ledger\Models\JournalEntry;
use App\Modules\Ledger\Models\LedgerAccount;
use App\Modules\Ledger\Services\LedgerIntegrityVerifier;
use App\Modules\Ledger\Services\LedgerPostingService;
use App\Modules\Shared\Domain\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsFinance;
use Tests\TestCase;

final class LedgerPostingServiceTest extends TestCase
{
    use BuildsFinance;
    use RefreshDatabase;

    public function test_unbalanced_journal_is_rejected_and_nothing_is_written(): void
    {
        $user = $this->provisionedUser();
        $bca = $this->openAccount($user, 'BCA', 'BANK', 'BCA');
        $salary = $this->category($user, 'SALARY');

        try {
            app(LedgerPostingService::class)->post($this->draft($user->id, [
                JournalLineDraft::debit($bca->ledger_account_id, Money::of(1000)),
                JournalLineDraft::credit($salary->ledger_account_id, Money::of(999)),
            ]));
            $this->fail('Unbalanced journal was posted.');
        } catch (UnbalancedJournalException $e) {
            $this->assertSame('LEDGER_UNBALANCED', $e->errorCode());
        }

        $this->assertSame(0, JournalEntry::query()->count());
        $this->assertSame('0.00', $this->balanceOf($bca));
    }

    public function test_single_line_and_non_positive_lines_are_rejected(): void
    {
        $user = $this->provisionedUser();
        $bca = $this->openAccount($user, 'BCA', 'BANK', 'BCA');
        $salary = $this->category($user, 'SALARY');
        $service = app(LedgerPostingService::class);

        $cases = [
            [JournalLineDraft::debit($bca->ledger_account_id, Money::of(1000))],
            [JournalLineDraft::debit($bca->ledger_account_id, Money::of(0)), JournalLineDraft::credit($salary->ledger_account_id, Money::of(0))],
            [JournalLineDraft::debit($bca->ledger_account_id, Money::of('-5')), JournalLineDraft::credit($salary->ledger_account_id, Money::of('-5'))],
        ];

        foreach ($cases as $lines) {
            try {
                $service->post($this->draft($user->id, $lines));
                $this->fail('Invalid journal was posted.');
            } catch (InvalidJournalException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, JournalEntry::query()->count());
    }

    public function test_posting_to_another_tenants_ledger_account_is_rejected(): void
    {
        $alice = $this->provisionedUser();
        $bob = $this->provisionedUser();
        $aliceBca = $this->openAccount($alice, 'BCA', 'BANK', 'BCA');
        $bobSalary = $this->category($bob, 'SALARY');

        $this->expectException(LedgerAccountNotUsableException::class);

        app(LedgerPostingService::class)->post($this->draft($bob->id, [
            JournalLineDraft::debit($aliceBca->ledger_account_id, Money::of(1000)),
            JournalLineDraft::credit($bobSalary->ledger_account_id, Money::of(1000)),
        ]));
    }

    public function test_multi_line_split_journal_updates_every_projection(): void
    {
        $user = $this->provisionedUser();
        $bca = $this->openAccount($user, 'BCA', 'BANK', 'BCA', 1_000_000);
        $food = $this->category($user, 'FOOD');
        $shopping = $this->category($user, 'SHOPPING');

        app(LedgerPostingService::class)->post($this->draft($user->id, [
            JournalLineDraft::debit($food->ledger_account_id, Money::of('120000.50')),
            JournalLineDraft::debit($shopping->ledger_account_id, Money::of('79999.50')),
            JournalLineDraft::credit($bca->ledger_account_id, Money::of(200000)),
        ]));

        $this->assertSame('800000.00', $this->balanceOf($bca));
        $this->assertSame('120000.50', LedgerAccount::query()->findOrFail($food->ledger_account_id)->balanceMoney()->amount);
        $this->assertSame('79999.50', LedgerAccount::query()->findOrFail($shopping->ledger_account_id)->balanceMoney()->amount);
        $this->assertLedgerIntact($user);
    }

    public function test_integrity_verifier_detects_a_tampered_balance_projection(): void
    {
        $user = $this->provisionedUser();
        $bca = $this->openAccount($user, 'BCA', 'BANK', 'BCA');
        $this->income($user, $bca, 1_000_000);
        $this->assertLedgerIntact($user);

        // Simulate out-of-band tampering (bypassing the posting service).
        DB::table('ledger_accounts')->where('id', $bca->ledger_account_id)->update(['balance' => '999999999.00']);

        $issues = app(LedgerIntegrityVerifier::class)->verify($user->id);
        $this->assertCount(1, $issues);
        $this->assertSame('projection_mismatch', $issues[0]['check']);
        $this->assertSame('1000000.00', $issues[0]['ledger']);

        $this->artisan('ledger:verify')->assertFailed();
    }

    public function test_ledger_verify_command_passes_on_consistent_ledger(): void
    {
        $user = $this->provisionedUser();
        $bca = $this->openAccount($user, 'BCA', 'BANK', 'BCA', 500_000);
        $this->expense($user, $bca, 100_000);

        $this->artisan('ledger:verify')->expectsOutput('Ledger integrity OK.')->assertSuccessful();
    }

    /**
     * @param  list<JournalLineDraft>  $lines
     */
    private function draft(int $userId, array $lines): JournalDraft
    {
        return new JournalDraft(
            userId: $userId,
            postedBy: $userId,
            entryDate: CarbonImmutable::today(),
            currency: 'IDR',
            lines: $lines,
        );
    }
}
