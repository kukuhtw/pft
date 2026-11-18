<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use App\Modules\Debts\Services\DebtRegisterService;
use App\Modules\Shared\Domain\Money;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Catatan hutang: when each loan happened, who it is owed to, what it was for, and what is left.
 * Only the logged-in user's LOAN accounts are read (scoped in DebtRegisterService).
 */
#[Title('Catatan Hutang')]
final class Debts extends Component
{
    /** "aktif" = still owed, "semua" = include paid-off debts. */
    #[Url]
    public string $show = 'aktif';

    #[On('entry-posted')]
    public function refreshAfterPosting(): void {}

    public function render(DebtRegisterService $register): View
    {
        /** @var User $user */
        $user = auth()->user();
        $all = $register->register($user);
        $rows = $this->show === 'semua' ? $all : array_values(array_filter($all, static fn (array $r): bool => ! $r['settled']));

        $currency = (string) config('finance.default_currency');
        $totalRemaining = array_reduce(
            array_filter($all, static fn (array $r): bool => $r['remaining']->currency === $currency),
            static fn (Money $sum, array $r): Money => $sum->plus($r['remaining']),
            Money::zero($currency),
        );

        return view('livewire.debts', [
            'rows' => $rows,
            'totalRemaining' => $totalRemaining,
            'openCount' => count(array_filter($all, static fn (array $r): bool => ! $r['settled'])),
            'settledCount' => count(array_filter($all, static fn (array $r): bool => $r['settled'])),
        ]);
    }
}
