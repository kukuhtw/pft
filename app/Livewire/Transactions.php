<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use App\Modules\Categories\Enums\CategoryKind;
use App\Modules\Categories\Models\TransactionCategory;
use App\Modules\Counterparties\Models\Counterparty;
use App\Modules\Interpretation\Enums\TransactionIntent;
use App\Modules\Interpretation\Services\IntentPostingService;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Shared\Support\MoneyInput;
use App\Modules\Transactions\Enums\TransactionStatus;
use App\Modules\Transactions\Models\Transaction;
use App\Modules\Transactions\Services\TransactionAmendmentService;
use App\Modules\Transactions\Services\TransactionService;
use Carbon\CarbonImmutable;
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
    public string $categoryId = '';

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    public ?string $flash = null;

    /** Transaction being edited (re-checked against the owner on save). */
    public ?string $editingId = null;

    public string $editAmount = '';

    public string $editDate = '';

    public string $editDescription = '';

    public string $editCategoryId = '';

    public string $editSourceId = '';

    public string $editDestinationId = '';

    public function edit(string $id): void
    {
        $t = Transaction::query()->where('user_id', $this->user()->id)->findOrFail($id);
        $this->resetErrorBag();
        $this->flash = null;

        if (! TransactionAmendmentService::editable($t)) {
            $this->addError('reverse', __('ledger.not_editable'));

            return;
        }

        $this->editingId = $t->id;
        $this->editAmount = MoneyInput::format((string) $t->getRawOriginal('amount'));
        $this->editDate = $t->transaction_date->toDateString();
        $this->editDescription = (string) $t->description;
        $this->editCategoryId = (string) $t->category_id;
        $this->editSourceId = (string) $t->source_account_id;
        $this->editDestinationId = (string) $t->destination_account_id;
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingId', 'editAmount', 'editDate', 'editDescription', 'editCategoryId', 'editSourceId', 'editDestinationId']);
        $this->resetErrorBag();
    }

    public function saveEdit(TransactionAmendmentService $amendments): void
    {
        $user = $this->user();
        $original = Transaction::query()->where('user_id', $user->id)->findOrFail((string) $this->editingId);
        $intent = $original->intent;
        $today = CarbonImmutable::now((string) config('finance.display_timezone'))->toDateString();

        $this->validate([
            'editAmount' => ['required', 'string', 'max:32'],
            'editDate' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'editDescription' => ['nullable', 'string', 'max:255'],
            'editCategoryId' => [IntentPostingService::needsCategory($intent) ? 'required' : 'nullable', 'nullable', 'string', 'ulid'],
            'editSourceId' => [IntentPostingService::needsSource($intent) ? 'required' : 'nullable', 'nullable', 'string', 'ulid'],
            'editDestinationId' => [IntentPostingService::needsDestination($intent) ? 'required' : 'nullable', 'nullable', 'string', 'ulid', 'different:editSourceId'],
        ], [], [
            'editAmount' => 'nominal', 'editDate' => 'tanggal', 'editDescription' => 'deskripsi', 'editCategoryId' => 'kategori',
            'editSourceId' => 'akun sumber', 'editDestinationId' => 'akun tujuan',
        ]);

        $money = MoneyInput::parse($this->editAmount, $original->currency_code);
        if ($money === null) {
            $this->addError('editAmount', 'Nominal tidak valid. Contoh: 50000, 2.000.000, 1,5jt, 500rb.');

            return;
        }

        try {
            $amendments->amend(
                $user,
                $original->id,
                $money,
                CarbonImmutable::createFromFormat('Y-m-d', $this->editDate, (string) config('finance.display_timezone')),
                trim($this->editDescription) !== '' ? trim($this->editDescription) : null,
                $this->editCategoryId !== '' ? $this->editCategoryId : null,
                $this->editSourceId !== '' ? $this->editSourceId : null,
                $this->editDestinationId !== '' ? $this->editDestinationId : null,
            );
        } catch (DomainException $e) {
            $this->addError('saveEdit', $e->getMessage());

            return;
        }

        $this->cancelEdit();
        $this->flash = 'Transaksi diubah. Versi lama tetap tersimpan di riwayat sebagai "diubah".';
        $this->dispatch('entry-posted');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['intent', 'counterpartyId', 'categoryId', 'dateFrom', 'dateTo'], true)) {
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
            ->when(Str::isUlid($this->categoryId) ? $this->categoryId : null, static fn (Builder $q, string $c) => $q->where('category_id', $c))
            ->when($date($this->dateFrom), static fn (Builder $q, string $d) => $q->where('transaction_date', '>=', $d))
            ->when($date($this->dateTo), static fn (Builder $q, string $d) => $q->where('transaction_date', '<=', $d))
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate(20);

        $editing = $this->editingId !== null
            ? Transaction::query()->where('user_id', $user->id)->find($this->editingId)
            : null;
        $editIntent = $editing?->intent;
        $posting = app(IntentPostingService::class);

        return view('livewire.transactions', [
            'transactions' => $transactions,
            'editing' => $editing,
            'editNeeds' => [
                'source' => IntentPostingService::needsSource($editIntent),
                'destination' => IntentPostingService::needsDestination($editIntent),
                'category' => IntentPostingService::needsCategory($editIntent),
            ],
            'editSources' => $editIntent !== null && IntentPostingService::needsSource($editIntent) ? $posting->accountsFor($user, $editIntent, 'source') : collect(),
            'editDestinations' => $editIntent !== null && IntentPostingService::needsDestination($editIntent) ? $posting->accountsFor($user, $editIntent, 'destination') : collect(),
            'editCategories' => $editIntent !== null && IntentPostingService::needsCategory($editIntent)
                ? TransactionCategory::query()->where('user_id', $user->id)->where('is_active', true)
                    ->where('kind', ($editIntent === TransactionIntent::EXPENSE ? CategoryKind::EXPENSE : CategoryKind::INCOME)->value)
                    ->orderBy('name')->get()
                : collect(),
            'intents' => TransactionIntent::cases(),
            'categories' => TransactionCategory::query()->where('user_id', $user->id)->orderBy('kind')->orderBy('name')->get(),
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
