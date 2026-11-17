<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Livewire\Budgets;
use App\Livewire\Categories;
use App\Livewire\Profile;
use App\Livewire\Reports;
use App\Modules\Budgets\Models\Budget;
use App\Modules\Budgets\Services\BudgetService;
use App\Modules\Categories\Enums\CategoryKind;
use App\Modules\Categories\Exceptions\CategoryNotUsableException;
use App\Modules\Categories\Models\TransactionCategory;
use App\Modules\Categories\Services\CategoryService;
use App\Modules\Reporting\Services\ReportService;
use App\Modules\Shared\Domain\Money;
use App\Modules\Transactions\Data\TransactionInput;
use App\Modules\Transactions\Services\TransactionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\Support\BuildsFinance;
use Tests\TestCase;

final class Phase2Test extends TestCase
{
    use BuildsFinance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function thisMonth(): string
    {
        return CarbonImmutable::now((string) config('finance.display_timezone'))->format('Y-m');
    }

    public function test_all_phase2_pages_render_for_a_logged_in_user(): void
    {
        $this->actingAs($this->provisionedUser());

        foreach (['/categories', '/budgets', '/reports', '/profile'] as $page) {
            $this->get($page)->assertOk();
        }
        $this->get('/reports')->assertSee('Pemasukan vs pengeluaran, 12 bulan');
    }

    public function test_custom_categories_can_be_created_renamed_and_hidden(): void
    {
        $user = $this->provisionedUser();
        $this->actingAs($user);

        Livewire::test(Categories::class)->set('newName', 'Jualan Kue')->set('newKind', 'INCOME')->call('create')->assertHasNoErrors();
        $cat = TransactionCategory::query()->with('ledgerAccount')->where('user_id', $user->id)->where('name', 'Jualan Kue')->sole();
        $this->assertSame('C_JUALAN_KUE', $cat->code);
        $this->assertSame('INCOME', $cat->ledgerAccount->class->value);

        Livewire::test(Categories::class)->set('newName', 'jualan kue')->set('newKind', 'INCOME')->call('create')->assertHasErrors('category');

        Livewire::test(Categories::class)->call('edit', $cat->id)->set('editingName', 'Jualan Kue & Roti')->call('rename')->assertHasNoErrors();
        $this->assertSame('Jualan Kue & Roti', $cat->fresh('ledgerAccount')->ledgerAccount->name);

        // Income posted with this category counts as income from an activity.
        $cash = $this->openAccount($user, 'Cash', 'CASH');
        $tx = $this->income($user, $cash, 300_000, 'C_JUALAN_KUE', description: 'hasil jualan');
        $this->assertSame('ACTIVITY_INCOME', $tx->intent?->value);

        Livewire::test(Categories::class)->call('toggle', $cat->id)->assertHasNoErrors();
        $this->assertFalse($cat->fresh()->is_active);
        $this->expectException(CategoryNotUsableException::class);
        $this->income($user, $cash, 1_000, 'C_JUALAN_KUE');
    }

    public function test_fallback_and_foreign_categories_are_protected(): void
    {
        $user = $this->provisionedUser();
        $other = $this->provisionedUser();
        $this->actingAs($user);

        Livewire::test(Categories::class)->call('toggle', $this->category($user, 'OTHER_EXPENSE')->id)->assertHasErrors('category');
        $this->assertTrue($this->category($user, 'OTHER_EXPENSE')->is_active);

        $this->expectException(CategoryNotUsableException::class);
        app(CategoryService::class)->rename($user, $this->category($other, 'FOOD')->id, 'Diretas');
    }

    public function test_budget_vs_actual_reads_the_ledger_and_ignores_transfers_loans_and_reversals(): void
    {
        $user = $this->provisionedUser();
        $bca = $this->openAccount($user, 'BCA', 'BANK', 'BCA', 5_000_000);
        $bni = $this->openAccount($user, 'BNI', 'BANK', 'BNI');
        $card = $this->openAccount($user, 'Kartu', 'CREDIT_CARD', 'BCA');
        $food = $this->category($user, 'FOOD');
        $this->actingAs($user);

        Livewire::test(Budgets::class)->set('categoryId', $food->id)->set('amount', '1jt')->call('save')->assertHasNoErrors();

        $this->expense($user, $bca, 600_000, 'FOOD');
        $this->expense($user, $card, 250_000, 'FOOD');         // card purchase is an expense
        $reversed = $this->expense($user, $bca, 400_000, 'FOOD');
        app(TransactionService::class)->reverse($user, $reversed->id);   // nets to zero
        $this->transfer($user, $bca, $bni, 1_000_000);         // not an expense
        $this->transfer($user, $bca, $card, 250_000);          // card payment: not an expense
        $this->expense($user, $bca, 50_000, 'TRANSPORTATION'); // no budget

        $status = app(BudgetService::class)->status($user, $this->thisMonth());
        $row = collect($status['rows'])->firstWhere('category.code', 'FOOD');
        $this->assertSame('850000.00', $row['actual']->amount);
        $this->assertSame(85, $row['percent']);
        $this->assertSame('warning', $row['state']);
        $this->assertSame('50000.00', $status['unbudgeted_actual']->amount);

        $this->expense($user, $bca, 300_000, 'FOOD');
        $row = collect(app(BudgetService::class)->status($user, $this->thisMonth())['rows'])->firstWhere('category.code', 'FOOD');
        $this->assertSame('over', $row['state']);
        $this->assertSame('-150000.00', $row['remaining']->amount);

        $this->get('/dashboard')->assertSee('Budget bulan ini perlu perhatian')->assertSee('Melebihi budget');
        $this->get('/budgets')->assertSee('Makanan &amp; Minuman', false)->assertSee('Rp1.150.000');
    }

    public function test_budget_rules_copy_and_removal(): void
    {
        $user = $this->provisionedUser();
        $other = $this->provisionedUser();
        $service = app(BudgetService::class);
        $now = CarbonImmutable::now((string) config('finance.display_timezone'));
        $last = $now->subMonthNoOverflow()->format('Y-m');

        $service->set($user, $this->category($user, 'FOOD')->id, $last, Money::of(1_000_000));
        $service->set($user, $this->category($user, 'TRANSPORTATION')->id, $last, Money::of(500_000));
        $service->set($user, $this->category($user, 'FOOD')->id, $now->format('Y-m'), Money::of(2_000_000));

        $this->actingAs($user);
        Livewire::test(Budgets::class)->call('copyPrevious')->assertSet('flash', '1 budget disalin dari bulan lalu.');
        $this->assertSame('2000000.00', Budget::query()->where('user_id', $user->id)->where('period', $now->format('Y-m'))
            ->where('category_id', $this->category($user, 'FOOD')->id)->value('amount'), 'Existing budget is kept.');

        // Zero removes; income or foreign categories are refused.
        $service->set($user, $this->category($user, 'FOOD')->id, $now->format('Y-m'), Money::zero());
        $this->assertSame(1, Budget::query()->where('user_id', $user->id)->where('period', $now->format('Y-m'))->count());

        Livewire::test(Budgets::class)->set('categoryId', $this->category($user, 'SALARY')->id)->set('amount', '100000')->call('save')->assertHasErrors('amount');
        Livewire::test(Budgets::class)->set('categoryId', $this->category($other, 'FOOD')->id)->set('amount', '100000')->call('save')->assertHasErrors('amount');
        $this->assertSame(0, Budget::query()->where('user_id', $other->id)->count());
    }

    public function test_reports_by_category_and_monthly_trend(): void
    {
        $user = $this->provisionedUser();
        $bca = $this->openAccount($user, 'BCA', 'BANK', 'BCA');
        $tz = (string) config('finance.display_timezone');
        $now = CarbonImmutable::now($tz);
        $lastMonth = $now->subMonthNoOverflow()->startOfMonth()->addDays(4);

        app(TransactionService::class)->recordIncome($user, new TransactionInput(amount: Money::of(8_000_000), transactionDate: $lastMonth, destinationAccountId: $bca->id, categoryId: $this->category($user, 'SALARY')->id));
        app(TransactionService::class)->recordExpense($user, new TransactionInput(amount: Money::of(1_000_000), transactionDate: $lastMonth, sourceAccountId: $bca->id, categoryId: $this->category($user, 'FOOD')->id));
        $this->income($user, $bca, 10_000_000, 'SALARY');
        $this->expense($user, $bca, 700_000, 'FOOD');
        $this->expense($user, $bca, 300_000, 'INTERNET');

        $reports = app(ReportService::class);
        $expense = $reports->byCategory($user, CategoryKind::EXPENSE, $now->startOfMonth(), $now);
        $this->assertSame(['FOOD' => '700000.00', 'INTERNET' => '300000.00'], collect($expense)->mapWithKeys(fn ($r) => [$r['category']->code => $r['total']->amount])->all());

        $trend = $reports->monthlyTrend($user, $now, 12);
        $this->assertCount(12, $trend);
        [$prev, $curr] = array_slice($trend, -2);
        $this->assertSame(['8000000.00', '1000000.00', '7000000.00'], [$prev['income']->amount, $prev['expense']->amount, $prev['net']->amount]);
        $this->assertSame(['10000000.00', '1000000.00'], [$curr['income']->amount, $curr['expense']->amount]);

        $this->actingAs($user);
        Livewire::test(Reports::class)->call('preset', 'last_month')
            ->assertViewHas('summary', fn ($s) => $s->totalIncome->amount === '8000000.00');
    }

    public function test_csv_export_is_scoped_and_neutralises_formulas(): void
    {
        $user = $this->provisionedUser();
        $bca = $this->openAccount($user, 'BCA', 'BANK', 'BCA', 1_000_000);
        app(TransactionService::class)->recordExpense($user, new TransactionInput(
            amount: Money::of(25_000), sourceAccountId: $bca->id, categoryId: $this->category($user, 'FOOD')->id,
            description: '=HYPERLINK("http://evil.test","klik")',
        ));
        $other = $this->provisionedUser();
        $this->income($other, $this->openAccount($other, 'Rahasia', 'CASH'), 999_000, 'GIFT');

        $today = CarbonImmutable::now((string) config('finance.display_timezone'))->toDateString();
        $csv = $this->actingAs($user)->get("/export/transactions.csv?from=2000-01-01&to={$today}")->assertOk()->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('25000.00', $csv);
        $this->assertStringNotContainsString('\n', $csv, 'Rows end with a real newline, not a literal backslash-n.');
        $this->assertSame(3, substr_count($csv, "\n"), 'Header + opening balance + expense.');
        $this->assertStringNotContainsString('999000', $csv);
        $this->assertStringNotContainsString('Rahasia', $csv);

        $this->get('/export/transactions.csv?from=2026-02-01&to=2026-01-01')->assertSessionHasErrors('to');
    }

    public function test_personal_data_export_contains_only_own_data_and_no_secrets(): void
    {
        $user = $this->provisionedUser(['name' => 'Ani']);
        $this->openAccount($user, 'BCA Ani', 'BANK', 'BCA');
        $other = $this->provisionedUser();
        $this->openAccount($other, 'BCA Orang Lain', 'BANK', 'BCA');

        $response = $this->actingAs($user)->get('/export/my-data.json')->assertOk()
            ->assertHeader('Content-Disposition')
            ->assertJsonPath('user.name', 'Ani')
            ->assertJsonPath('accounts.0.name', 'BCA Ani');

        $this->assertStringNotContainsString('BCA Orang Lain', $response->getContent());
        $this->assertStringNotContainsString('password', $response->getContent());
    }

    public function test_password_change_requires_current_password_and_revokes_tokens(): void
    {
        $user = $this->provisionedUser(['password' => 'Rahasia-Kuat-123']);
        $user->createToken('telegram');
        $this->actingAs($user);

        Livewire::test(Profile::class)
            ->set('current_password', 'salah')->set('password', 'Baru-Lebih-Kuat-456')->set('password_confirmation', 'Baru-Lebih-Kuat-456')
            ->call('changePassword')->assertHasErrors('current_password');
        $this->assertSame(1, $user->tokens()->count());

        Livewire::test(Profile::class)
            ->set('current_password', 'Rahasia-Kuat-123')->set('password', 'Baru-Lebih-Kuat-456')->set('password_confirmation', 'Baru-Lebih-Kuat-456')
            ->call('changePassword')->assertHasNoErrors();

        $this->assertTrue(Hash::check('Baru-Lebih-Kuat-456', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count(), 'API tokens are revoked.');

        Livewire::test(Profile::class)->set('name', 'Ani Baru')->call('saveName')->assertHasNoErrors();
        $this->assertSame('Ani Baru', $user->fresh()->name);
    }
}
