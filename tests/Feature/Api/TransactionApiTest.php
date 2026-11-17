<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Modules\Transactions\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsFinance;
use Tests\TestCase;

final class TransactionApiTest extends TestCase
{
    use BuildsFinance;
    use RefreshDatabase;

    public function test_register_login_and_me(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Budi',
            'email' => 'budi@example.test',
            'password' => 'Rahasia-Kuat-123',
            'password_confirmation' => 'Rahasia-Kuat-123',
        ])->assertCreated()->assertJsonStructure(['data' => ['user' => ['id', 'name', 'email'], 'token']]);

        $token = $this->postJson('/api/v1/auth/login', ['email' => 'budi@example.test', 'password' => 'Rahasia-Kuat-123'])
            ->assertOk()
            ->json('data.token');

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'budi@example.test')
            ->assertJsonMissingPath('data.password');

        // Registration provisions default categories (Bahasa Indonesia).
        $this->withToken($token)->getJson('/api/v1/categories?kind=EXPENSE')->assertOk()->assertJsonFragment(['code' => 'FOOD', 'name' => 'Makanan & Minuman']);
    }

    public function test_login_failure_does_not_reveal_whether_email_exists(): void
    {
        $this->provisionedUser(['email' => 'ada@example.test']);

        $a = $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.test', 'password' => 'wrong-password'])->assertStatus(422);
        $b = $this->postJson('/api/v1/auth/login', ['email' => 'tidak-ada@example.test', 'password' => 'wrong-password'])->assertStatus(422);

        $this->assertSame($a->json('error.message'), $b->json('error.message'));
    }

    public function test_salary_simulation_through_the_http_api(): void
    {
        $user = $this->provisionedUser();
        Sanctum::actingAs($user);

        $ids = [];
        foreach (['BCA' => ['BANK', 'BCA'], 'BNI' => ['BANK', 'BNI'], 'Cash' => ['CASH', null], 'Mandiri' => ['BANK', 'MANDIRI'], 'CIMB Niaga' => ['BANK', 'CIMB']] as $name => [$type, $inst]) {
            $ids[$name] = $this->postJson('/api/v1/accounts', array_filter(['name' => $name, 'account_type' => $type, 'institution_code' => $inst]))
                ->assertCreated()
                ->json('data.id');
        }

        $salary = $this->category($user, 'SALARY')->id;
        $food = $this->category($user, 'FOOD')->id;

        $this->post_('income', ['amount' => 10000000, 'destination_account_id' => $ids['BCA'], 'category_id' => $salary, 'description' => 'Gaji masuk BCA'])->assertCreated();
        $this->post_('transfer', ['amount' => '2000000', 'source_account_id' => $ids['BCA'], 'destination_account_id' => $ids['BNI']])->assertCreated()->assertJsonPath('data.type', 'INTERNAL_TRANSFER');
        $this->post_('transfer', ['amount' => '2000000', 'source_account_id' => $ids['BCA'], 'destination_account_id' => $ids['Cash']])->assertCreated();
        $this->post_('transfer', ['amount' => '1000000', 'source_account_id' => $ids['BCA'], 'destination_account_id' => $ids['Mandiri']])->assertCreated();
        $this->post_('transfer', ['amount' => '1000000', 'source_account_id' => $ids['BCA'], 'destination_account_id' => $ids['CIMB Niaga']])->assertCreated();
        $this->post_('expense', ['amount' => 35000, 'source_account_id' => $ids['Cash'], 'category_id' => $food, 'merchant' => 'Sate', 'payment_method' => 'CASH'])->assertCreated();
        $this->post_('expense', ['amount' => 10000, 'source_account_id' => $ids['BNI'], 'category_id' => $food, 'merchant' => 'Minuman', 'payment_method' => 'QRIS'])
            ->assertCreated()
            ->assertJsonPath('data.amount.formatted', 'Rp10.000');

        $balances = collect($this->getJson('/api/v1/accounts')->assertOk()->json('data'))->pluck('balance.amount', 'name')->all();
        $this->assertSame([
            'BCA' => '4000000.00',
            'BNI' => '1990000.00',
            'CIMB Niaga' => '1000000.00',
            'Cash' => '1965000.00',
            'Mandiri' => '1000000.00',
        ], $balances);

        $this->getJson('/api/v1/reports/summary?date_from=2000-01-01')
            ->assertOk()
            ->assertJsonPath('data.total_income.amount', '10000000.00')
            ->assertJsonPath('data.total_expenses.amount', '45000.00')
            ->assertJsonPath('data.total_internal_transfers.amount', '6000000.00')
            ->assertJsonPath('data.total_assets.amount', '9955000.00')
            ->assertJsonPath('data.net_cash_flow.amount', '9955000.00')
            ->assertJsonPath('data.total_assets.formatted', 'Rp9.955.000');

        $this->getJson('/api/v1/transactions?per_page=5')->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.total', 7);
        $this->assertLedgerIntact($user);
    }

    public function test_posting_requires_idempotency_key_and_replays_safely(): void
    {
        $user = $this->provisionedUser();
        Sanctum::actingAs($user);
        $bca = $this->openAccount($user, 'BCA', 'BANK', 'BCA');
        $payload = ['amount' => 1000000, 'destination_account_id' => $bca->id];

        $this->postJson('/api/v1/transactions/income', $payload)->assertStatus(400)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');

        $headers = ['Idempotency-Key' => 'gaji-nov-2026-0001'];
        $first = $this->postJson('/api/v1/transactions/income', $payload, $headers)->assertCreated();
        $replay = $this->postJson('/api/v1/transactions/income', $payload, $headers)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json('data.id'), $replay->json('data.id'));

        $this->postJson('/api/v1/transactions/income', [...$payload, 'amount' => 5], $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');

        $this->assertSame('1000000.00', $this->balanceOf($bca));
    }

    public function test_float_amounts_and_over_precise_amounts_are_rejected(): void
    {
        $user = $this->provisionedUser();
        Sanctum::actingAs($user);
        $bca = $this->openAccount($user, 'BCA', 'BANK', 'BCA');

        foreach ([1000.5, '10.005', '-100', 0, 'abc'] as $amount) {
            $this->post_('income', ['amount' => $amount, 'destination_account_id' => $bca->id])
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'VALIDATION_FAILED')
                ->assertJsonValidationErrors('amount', 'error.details');
        }

        $this->post_('income', ['amount' => '1000.50', 'destination_account_id' => $bca->id])->assertCreated();
        $this->assertSame('1000.50', $this->balanceOf($bca));
    }

    public function test_future_dated_transactions_are_rejected(): void
    {
        $user = $this->provisionedUser();
        Sanctum::actingAs($user);
        $bca = $this->openAccount($user, 'BCA', 'BANK', 'BCA');

        $this->post_('income', ['amount' => 1000, 'destination_account_id' => $bca->id, 'transaction_date' => now()->addDays(3)->toDateString()])
            ->assertStatus(422);
    }

    public function test_12_tenant_isolation_over_the_api(): void
    {
        $alice = $this->provisionedUser();
        $bob = $this->provisionedUser();
        $aliceBca = $this->openAccount($alice, 'BCA', 'BANK', 'BCA', 1_000_000);
        $aliceTx = $this->expense($alice, $aliceBca, 50_000);
        $bobCash = $this->openAccount($bob, 'Cash', 'CASH', opening: 100_000);

        Sanctum::actingAs($bob);

        // Cannot read Alice's resources (404, not 403: existence is not disclosed).
        $this->getJson("/api/v1/accounts/{$aliceBca->id}")->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        $this->getJson("/api/v1/transactions/{$aliceTx->id}")->assertNotFound();
        $this->getJson('/api/v1/accounts')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $bobCash->id);
        $this->getJson('/api/v1/transactions')->assertOk()->assertJsonMissing(['id' => $aliceTx->id]);

        // Cannot post against, transfer into, or reverse Alice's data.
        $this->post_('expense', ['amount' => 1000, 'source_account_id' => $aliceBca->id])->assertStatus(422)->assertJsonPath('error.code', 'ACCOUNT_NOT_USABLE');
        $this->post_('transfer', ['amount' => 1000, 'source_account_id' => $bobCash->id, 'destination_account_id' => $aliceBca->id])->assertStatus(422);
        $this->post_('income', ['amount' => 1000, 'destination_account_id' => $bobCash->id, 'category_id' => $this->category($alice, 'SALARY')->id])
            ->assertStatus(422)->assertJsonPath('error.code', 'CATEGORY_NOT_USABLE');
        $this->postJson("/api/v1/transactions/{$aliceTx->id}/reverse", [], ['Idempotency-Key' => (string) Str::uuid()])->assertNotFound();

        // Bob's summary contains only Bob's money.
        $this->getJson('/api/v1/reports/summary?date_from=2000-01-01')->assertOk()->assertJsonPath('data.total_assets.amount', '100000.00');

        $this->assertSame('950000.00', $this->balanceOf($aliceBca));
        $this->assertSame(1, Transaction::query()->where('user_id', $alice->id)->where('type', 'EXPENSE')->count());
    }

    public function test_reverse_endpoint(): void
    {
        $user = $this->provisionedUser();
        Sanctum::actingAs($user);
        $cash = $this->openAccount($user, 'Cash', 'CASH', opening: 100_000);
        $tx = $this->expense($user, $cash, 35_000);

        $this->postJson("/api/v1/transactions/{$tx->id}/reverse", ['reason' => 'Duplikat'], ['Idempotency-Key' => 'rev-'.$tx->id])
            ->assertOk()
            ->assertJsonPath('data.status', 'REVERSED')
            ->assertJsonCount(2, 'data.journal_entries');

        $this->postJson("/api/v1/transactions/{$tx->id}/reverse", [], ['Idempotency-Key' => 'rev2-'.$tx->id])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'TRANSACTION_NOT_REVERSIBLE');

        $this->assertSame('100000.00', $this->balanceOf($cash));
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/accounts')->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_account_creation_accepts_only_last_four_digits(): void
    {
        Sanctum::actingAs($this->provisionedUser());

        $this->postJson('/api/v1/accounts', ['name' => 'BCA', 'account_type' => 'BANK', 'account_number_last4' => '1234567890'])
            ->assertStatus(422)->assertJsonValidationErrors('account_number_last4', 'error.details');

        $this->postJson('/api/v1/accounts', ['name' => 'BCA', 'account_type' => 'BANK', 'institution_code' => 'BCA', 'account_number_last4' => '7890', 'opening_balance' => '250000'])
            ->assertCreated()
            ->assertJsonPath('data.account_number_last4', '7890')
            ->assertJsonPath('data.balance.amount', '250000.00')
            ->assertJsonPath('data.ledger_class', 'ASSET');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function post_(string $kind, array $payload): TestResponse
    {
        return $this->postJson("/api/v1/transactions/{$kind}", $payload, ['Idempotency-Key' => (string) Str::uuid()]);
    }
}
