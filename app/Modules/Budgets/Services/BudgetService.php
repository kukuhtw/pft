<?php

declare(strict_types=1);

namespace App\Modules\Budgets\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Budgets\Models\Budget;
use App\Modules\Categories\Enums\CategoryKind;
use App\Modules\Categories\Models\TransactionCategory;
use App\Modules\Reporting\Services\ReportService;
use App\Modules\Shared\Domain\Money;
use App\Modules\Transactions\Exceptions\InvalidTransactionException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Monthly budgets per expense category. Budgets never touch the ledger; "actual" is always the
 * ledger's expense total for that category in that month (reversals and transfers are handled there).
 */
final class BudgetService
{
    /** Spending at or above this share of the budget is a warning. */
    public const int WARNING_PERCENT = 80;

    public function __construct(
        private readonly ReportService $reports,
        private readonly AuditLogger $audit,
    ) {}

    public static function assertPeriod(string $period): CarbonImmutable
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw new InvalidTransactionException(__('ledger.budget_period_invalid'));
        }

        return CarbonImmutable::createFromFormat('!Y-m', $period, (string) config('finance.display_timezone'));
    }

    /** Set (or with a zero amount, remove) the budget for one category and month. */
    public function set(User $user, string $categoryId, string $period, Money $amount): ?Budget
    {
        self::assertPeriod($period);

        if ($amount->isNegative()) {
            throw new InvalidTransactionException(__('ledger.amount_positive'));
        }

        return DB::transaction(function () use ($user, $categoryId, $period, $amount): ?Budget {
            $category = TransactionCategory::query()
                ->where('user_id', $user->id)->whereKey($categoryId)->where('kind', CategoryKind::EXPENSE->value)->first()
                ?? throw new InvalidTransactionException(__('ledger.budget_category_invalid'));

            $existing = Budget::query()->where('user_id', $user->id)->where('category_id', $category->id)->where('period', $period)->first();

            if ($amount->isZero()) {
                $existing?->delete();
                $this->audit->record('budget.removed', $user, $category, ['period' => $period]);

                return null;
            }

            $budget = $existing ?? new Budget(['user_id' => $user->id, 'category_id' => $category->id, 'period' => $period]);
            $budget->fill(['amount' => $amount->amount, 'currency_code' => $amount->currency])->save();
            $this->audit->record('budget.set', $user, $budget, ['period' => $period, 'amount' => $amount->amount]);

            return $budget;
        });
    }

    /** Copy every budget of $from into $to, keeping budgets already set in $to. Returns the number copied. */
    public function copy(User $user, string $from, string $to): int
    {
        self::assertPeriod($from);
        self::assertPeriod($to);

        return DB::transaction(function () use ($user, $from, $to): int {
            $already = Budget::query()->where('user_id', $user->id)->where('period', $to)->pluck('category_id')->all();
            $copied = 0;

            foreach (Budget::query()->where('user_id', $user->id)->where('period', $from)->whereNotIn('category_id', $already)->get() as $source) {
                Budget::create([
                    'user_id' => $user->id,
                    'category_id' => $source->category_id,
                    'period' => $to,
                    'amount' => $source->getRawOriginal('amount'),
                    'currency_code' => $source->currency_code,
                ]);
                $copied++;
            }

            return $copied;
        });
    }

    /**
     * Budget vs actual for a month: every budgeted category, plus categories with spending but no budget.
     *
     * @return array{rows: list<array{category: TransactionCategory, budget: Money|null, actual: Money, remaining: Money|null, percent: int|null, state: string}>, total_budget: Money, total_actual_budgeted: Money, unbudgeted_actual: Money}
     */
    public function status(User $user, string $period, string $currency = 'IDR'): array
    {
        $month = self::assertPeriod($period);
        $actuals = [];
        foreach ($this->reports->byCategory($user, CategoryKind::EXPENSE, $month->startOfMonth(), $month->endOfMonth(), $currency) as $row) {
            $actuals[$row['category']->id] = $row;
        }

        $budgets = Budget::query()->with('category')->where('user_id', $user->id)->where('period', $period)->where('currency_code', $currency)->get();

        $rows = [];
        $totalBudget = Money::zero($currency);
        $totalActualBudgeted = Money::zero($currency);
        $unbudgeted = Money::zero($currency);

        foreach ($budgets as $budget) {
            $limit = $budget->money();
            $actual = $actuals[$budget->category_id]['total'] ?? Money::zero($currency);
            $percent = (int) floor((float) bcdiv(bcmul($actual->amount, '100', 2), $limit->amount, 2));
            unset($actuals[$budget->category_id]);

            $totalBudget = $totalBudget->plus($limit);
            $totalActualBudgeted = $totalActualBudgeted->plus($actual);
            $rows[] = [
                'category' => $budget->category,
                'budget' => $limit,
                'actual' => $actual,
                'remaining' => $limit->minus($actual),
                'percent' => $percent,
                'state' => match (true) {
                    $percent > 100 => 'over',
                    $percent >= self::WARNING_PERCENT => 'warning',
                    default => 'ok',
                },
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['percent'] <=> $a['percent']);

        foreach ($actuals as $row) {
            if ($row['total']->isPositive()) {
                $unbudgeted = $unbudgeted->plus($row['total']);
                $rows[] = ['category' => $row['category'], 'budget' => null, 'actual' => $row['total'], 'remaining' => null, 'percent' => null, 'state' => 'unbudgeted'];
            }
        }

        return ['rows' => $rows, 'total_budget' => $totalBudget, 'total_actual_budgeted' => $totalActualBudgeted, 'unbudgeted_actual' => $unbudgeted];
    }
}
