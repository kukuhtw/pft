<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use App\Modules\Budgets\Services\BudgetService;
use App\Modules\Categories\Enums\CategoryKind;
use App\Modules\Categories\Models\TransactionCategory;
use App\Modules\Shared\Domain\Money;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Shared\Support\MoneyInput;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Budget')]
final class Budgets extends Component
{
    /** YYYY-MM */
    #[Url]
    public string $period = '';

    public string $categoryId = '';

    public string $amount = '';

    public ?string $flash = null;

    public function mount(): void
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->period) !== 1) {
            $this->period = CarbonImmutable::now((string) config('finance.display_timezone'))->format('Y-m');
        }
    }

    public function shiftMonth(int $delta): void
    {
        $this->period = BudgetService::assertPeriod($this->period)->addMonthsNoOverflow(max(-12, min(12, $delta)))->format('Y-m');
        $this->flash = null;
    }

    public function edit(string $categoryId, string $current = ''): void
    {
        $this->categoryId = $categoryId;
        $this->amount = $current;
    }

    public function save(BudgetService $budgets): void
    {
        $this->validate([
            'categoryId' => ['required', 'string', 'ulid'],
            'amount' => ['required', 'string', 'max:32'],
        ], [], ['categoryId' => 'kategori', 'amount' => 'nominal']);

        $currency = (string) config('finance.default_currency');
        $money = trim($this->amount) === '0' ? Money::zero($currency) : MoneyInput::parse($this->amount, $currency);
        if ($money === null) {
            $this->addError('amount', 'Nominal tidak valid. Contoh: 1.500.000, 2jt, atau 0 untuk menghapus.');

            return;
        }

        try {
            $budgets->set($this->user(), $this->categoryId, $this->period, $money);
        } catch (DomainException $e) {
            $this->addError('amount', $e->getMessage());

            return;
        }

        $this->flash = $money->isZero() ? 'Budget dihapus.' : 'Budget disimpan.';
        $this->reset(['categoryId', 'amount']);
    }

    public function copyPrevious(BudgetService $budgets): void
    {
        $previous = BudgetService::assertPeriod($this->period)->subMonthNoOverflow()->format('Y-m');
        $copied = $budgets->copy($this->user(), $previous, $this->period);
        $this->flash = $copied > 0 ? "{$copied} budget disalin dari bulan lalu." : 'Tidak ada budget baru untuk disalin.';
    }

    public function render(BudgetService $budgets): View
    {
        $month = BudgetService::assertPeriod($this->period);
        $status = $budgets->status($this->user(), $this->period);

        return view('livewire.budgets', [
            'month' => $month,
            'status' => $status,
            'expenseCategories' => TransactionCategory::query()->where('user_id', $this->user()->id)
                ->where('kind', CategoryKind::EXPENSE->value)->where('is_active', true)->orderBy('name')->get(),
            'isCurrentMonth' => $month->format('Y-m') === CarbonImmutable::now((string) config('finance.display_timezone'))->format('Y-m'),
        ]);
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
