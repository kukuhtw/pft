<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use App\Modules\Categories\Enums\CategoryKind;
use App\Modules\Interpretation\Enums\TransactionIntent;
use App\Modules\Reporting\Services\DashboardService;
use App\Modules\Reporting\Services\FinancialSummaryService;
use App\Modules\Reporting\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Laporan')]
final class Reports extends Component
{
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(): void
    {
        $now = CarbonImmutable::now((string) config('finance.display_timezone'));
        if (! $this->validDate($this->from)) {
            $this->from = $now->startOfMonth()->toDateString();
        }
        if (! $this->validDate($this->to)) {
            $this->to = $now->toDateString();
        }
    }

    public function preset(string $preset): void
    {
        $now = CarbonImmutable::now((string) config('finance.display_timezone'));
        [$from, $to] = match ($preset) {
            'last_month' => [$now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()],
            'last_3_months' => [$now->subMonthsNoOverflow(2)->startOfMonth(), $now],
            'this_year' => [$now->startOfYear(), $now],
            default => [$now->startOfMonth(), $now],
        };
        $this->from = $from->toDateString();
        $this->to = $to->toDateString();
    }

    public function render(ReportService $reports, FinancialSummaryService $summaries, DashboardService $dashboard): View
    {
        $user = $this->user();
        $tz = (string) config('finance.display_timezone');
        $from = $this->validDate($this->from) ? CarbonImmutable::createFromFormat('Y-m-d', $this->from, $tz) : CarbonImmutable::now($tz)->startOfMonth();
        $to = $this->validDate($this->to) ? CarbonImmutable::createFromFormat('Y-m-d', $this->to, $tz) : CarbonImmutable::now($tz);
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        $trend = $reports->monthlyTrend($user, $to, 12);
        // Only for scaling bar heights; the numbers shown come from Money.
        $peak = max([1.0, ...array_map(static fn (array $m): float => max((float) $m['income']->amount, (float) $m['expense']->amount), $trend)]);
        // Round the axis up to a clean 1/2/5 × 10ⁿ so the ticks read 0 / 10jt / 20jt, not 0 / 6,3jt / 12,6jt.
        $magnitude = 10 ** floor(log10($peak));
        foreach ([1, 2, 5, 10] as $step) {
            if ($step * $magnitude >= $peak) {
                $peak = $step * $magnitude;
                break;
            }
        }

        return view('livewire.reports', [
            'fromDate' => $from,
            'toDate' => $to,
            'summary' => $summaries->summarize($user, $from, $to),
            'income' => $reports->byCategory($user, CategoryKind::INCOME, $from, $to),
            'expense' => $reports->byCategory($user, CategoryKind::EXPENSE, $from, $to),
            'inflows' => $dashboard->inflowsByIntent($user, $from, $to),
            'trend' => $trend,
            'peak' => $peak,
            'intentEnum' => TransactionIntent::class,
        ]);
    }

    private function validDate(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4));
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
