<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Transactions\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\BuildsFinance;
use Tests\TestCase;

/**
 * Master-prompt §19 #11: concurrent postings from separate OS processes stay consistent.
 * Needs real row locks, so it runs only on MySQL/MariaDB. Data must be committed for the
 * worker processes to see it, so this test migrates fresh before and after instead of using
 * RefreshDatabase's wrapping transaction.
 */
final class ConcurrentPostingTest extends TestCase
{
    use BuildsFinance;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Concurrency test requires MySQL/MariaDB row locking.');
        }

        $this->artisan('migrate:fresh');
    }

    protected function tearDown(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->artisan('migrate:fresh');
        }

        parent::tearDown();
    }

    public function test_concurrent_expenses_never_overdraw_an_account(): void
    {
        $user = $this->provisionedUser();
        $cash = $this->openAccount($user, 'Cash', 'CASH', opening: 100_000);
        $food = $this->category($user, 'FOOD');

        // 10 parallel Rp15,000 expenses against Rp100,000 → exactly 6 can succeed.
        $results = $this->runWorkers(10, fn (int $i) => ['expense', $user->id, $cash->id, $food->id, '15000']);

        $this->assertSame(6, $results[0] ?? 0, 'Successful postings: '.json_encode($results));
        $this->assertSame(4, $results[3] ?? 0, 'Insufficient-funds rejections: '.json_encode($results));
        $this->assertSame('10000.00', $this->balanceOf($cash));
        $this->assertLedgerIntact();
    }

    public function test_concurrent_requests_with_same_idempotency_key_post_once(): void
    {
        $user = $this->provisionedUser();
        $bca = $this->openAccount($user, 'BCA', 'BANK', 'BCA');
        $salary = $this->category($user, 'SALARY');

        $results = $this->runWorkers(6, fn (int $i) => ['income', $user->id, $bca->id, $salary->id, '1000000', null, 'same-key-concurrent-01']);

        $this->assertSame(6, $results[0] ?? 0, 'All requests should succeed (1 post + 5 replays): '.json_encode($results));
        $this->assertSame(1, Transaction::query()->where('user_id', $user->id)->where('type', 'INCOME')->count());
        $this->assertSame('1000000.00', $this->balanceOf($bca));
        $this->assertLedgerIntact();
    }

    /**
     * @param  callable(int): array<int, mixed>  $args
     * @return array<int, int> exit code => count
     */
    private function runWorkers(int $count, callable $args): array
    {
        $startAt = (string) (microtime(true) + 3.0);
        $processes = [];

        for ($i = 0; $i < $count; $i++) {
            $a = $args($i);
            $cmd = [PHP_BINARY, base_path('tests/Concurrency/post_worker.php'), $a[0], (string) $a[1], $a[2], $a[3], $a[4], $startAt];
            if (isset($a[6])) {
                $cmd[] = $a[6];
            }
            $process = new Process($cmd, base_path(), null, null, 60);
            $process->start();
            $processes[] = $process;
        }

        $codes = [];
        foreach ($processes as $process) {
            $process->wait();
            $code = (int) $process->getExitCode();
            if ($code === 1) {
                $this->fail('Worker crashed: '.$process->getErrorOutput());
            }
            $codes[$code] = ($codes[$code] ?? 0) + 1;
        }

        return $codes;
    }
}
