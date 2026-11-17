<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use App\Modules\Accounts\Enums\AccountStatus;
use App\Modules\Accounts\Models\FinancialAccount;
use App\Modules\Budgets\Services\BudgetService;
use App\Modules\Interpretation\Enums\TransactionIntent;
use App\Modules\Reporting\Services\DashboardService;
use App\Modules\Reporting\Services\FinancialSummaryService;
use App\Modules\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Dashboard')]
final class Dashboard extends Component
{
    /** this_month | last_month | this_year */
    #[Url]
    public string $period = 'this_month';

    /** Re-render after QuickEntry posts. */
    #[On('entry-posted')]
    public function refreshAfterPosting(): void {}

    public function render(FinancialSummaryService $summaries, DashboardService $dashboard, BudgetService $budgets): View
    {
        /** @var User $user */
        $user = auth()->user();
        [$from, $to] = $this->range();
        $thisMonth = CarbonImmutable::now((string) config('finance.display_timezone'))->format('Y-m');

        return view('livewire.dashboard', [
            // This month's budgets that need attention (≥ warning threshold), worst first.
            'budgetAlerts' => array_slice(array_values(array_filter(
                $budgets->status($user, $thisMonth)['rows'],
                static fn (array $r): bool => in_array($r['state'], ['warning', 'over'], true),
            )), 0, 4),
            'from' => $from,
            'to' => $to,
            'summary' => $summaries->summarize($user, $from, $to),
            'inflows' => $dashboard->inflowsByIntent($user, $from, $to),
            'debts' => $dashboard->debtsByCounterparty($user),
            'accounts' => FinancialAccount::query()->with(['ledgerAccount', 'counterparty'])
                ->where('user_id', $user->id)->where('status', AccountStatus::ACTIVE)->orderBy('name')->get(),
            'recent' => Transaction::query()->with(['sourceAccount', 'destinationAccount', 'category', 'counterparty'])
                ->where('user_id', $user->id)->orderByDesc('transaction_date')->orderByDesc('id')->limit(8)->get(),
            'intentEnum' => TransactionIntent::class,
        ]);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function range(): array
    {
        $now = CarbonImmutable::now((string) config('finance.display_timezone'));

        return match ($this->period) {
            'last_month' => [$now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()],
            'this_year' => [$now->startOfYear(), $now],
            default => [$now->startOfMonth(), $now],
        };
    }
}
