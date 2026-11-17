<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use App\Modules\Counterparties\Models\Counterparty;
use App\Modules\Interpretation\Enums\TransactionIntent;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Transactions\Enums\TransactionStatus;
use App\Modules\Transactions\Models\Transaction;
use App\Modules\Transactions\Services\TransactionService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Transaksi')]
final class Transactions extends Component
{
    use WithPagination;

    #[Url]
    public string $intent = '';

    #[Url]
    public string $counterpartyId = '';

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    public ?string $flash = null;

    public function updated(string $property): void
    {
        if (in_array($property, ['intent', 'counterpartyId', 'dateFrom', 'dateTo'], true)) {
            $this->resetPage();
        }
    }

    #[On('entry-posted')]
    public function refreshAfterPosting(): void {}

    /** Correction = mirror journal entry; the original stays in the books (immutable ledger). */
    public function reverse(string $id, TransactionService $transactions): void
    {
        try {
            $transactions->reverse($this->user(), $id, 'Dibatalkan dari dashboard', 'web-reverse-'.$id);
            $this->flash = 'Transaksi dibatalkan dengan jurnal pembalik.';
        } catch (DomainException $e) {
            $this->addError('reverse', $e->getMessage());
        }
    }

    public function render(): View
    {
        $user = $this->user();
        $intent = TransactionIntent::tryFrom($this->intent);
        $date = static fn (string $d): ?string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 ? $d : null;

        $transactions = Transaction::query()
            ->with(['sourceAccount', 'destinationAccount', 'category', 'counterparty'])
            ->where('user_id', $user->id)
            ->when($intent, static fn (Builder $q, TransactionIntent $i) => $q->where('intent', $i->value))
            ->when(Str::isUlid($this->counterpartyId) ? $this->counterpartyId : null, static fn (Builder $q, string $c) => $q->where('counterparty_id', $c))
            ->when($date($this->dateFrom), static fn (Builder $q, string $d) => $q->where('transaction_date', '>=', $d))
            ->when($date($this->dateTo), static fn (Builder $q, string $d) => $q->where('transaction_date', '<=', $d))
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate(20);

        return view('livewire.transactions', [
            'transactions' => $transactions,
            'intents' => TransactionIntent::cases(),
            'counterparties' => Counterparty::query()->where('user_id', $user->id)->orderBy('name')->get(),
            'posted' => TransactionStatus::POSTED,
        ]);
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
