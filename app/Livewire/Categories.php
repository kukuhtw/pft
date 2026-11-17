<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use App\Modules\Categories\Enums\CategoryKind;
use App\Modules\Categories\Models\TransactionCategory;
use App\Modules\Categories\Services\CategoryService;
use App\Modules\Reporting\Services\ReportService;
use App\Modules\Shared\Exceptions\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Kategori')]
final class Categories extends Component
{
    public string $newName = '';

    public string $newKind = 'EXPENSE';

    public string $editingId = '';

    public string $editingName = '';

    public ?string $flash = null;

    public function create(CategoryService $categories): void
    {
        $this->validate([
            'newName' => ['required', 'string', 'max:128'],
            'newKind' => ['required', Rule::enum(CategoryKind::class)],
        ], [], ['newName' => 'nama kategori']);

        $this->attempt(function () use ($categories): void {
            $category = $categories->createCustom($this->user(), CategoryKind::from($this->newKind), $this->newName, (string) config('finance.default_currency'));
            $this->flash = 'Kategori "'.$category->name.'" dibuat.';
            $this->reset('newName');
        });
    }

    public function edit(string $id): void
    {
        $category = TransactionCategory::query()->where('user_id', $this->user()->id)->findOrFail($id);
        $this->editingId = $category->id;
        $this->editingName = $category->name;
    }

    public function rename(CategoryService $categories): void
    {
        $this->validate(['editingName' => ['required', 'string', 'max:128']], [], ['editingName' => 'nama']);

        $this->attempt(function () use ($categories): void {
            $categories->rename($this->user(), $this->editingId, $this->editingName);
            $this->flash = 'Nama kategori diperbarui.';
            $this->reset(['editingId', 'editingName']);
        });
    }

    public function toggle(string $id, CategoryService $categories): void
    {
        $this->attempt(function () use ($id, $categories): void {
            $category = TransactionCategory::query()->where('user_id', $this->user()->id)->findOrFail($id);
            $categories->setActive($this->user(), $id, ! $category->is_active);
            $this->flash = $category->is_active ? 'Kategori diaktifkan kembali.' : 'Kategori disembunyikan dari input baru. Riwayatnya tetap ada.';
        });
    }

    public function render(ReportService $reports): View
    {
        $user = $this->user();
        $now = CarbonImmutable::now((string) config('finance.display_timezone'));
        $totals = [];
        foreach ([CategoryKind::INCOME, CategoryKind::EXPENSE] as $kind) {
            foreach ($reports->byCategory($user, $kind, $now->startOfMonth(), $now) as $row) {
                $totals[$row['category']->id] = $row['total'];
            }
        }

        return view('livewire.categories', [
            'groups' => TransactionCategory::query()->where('user_id', $user->id)
                ->orderByDesc('is_active')->orderBy('name')->get()->groupBy(static fn (TransactionCategory $c) => $c->kind->value),
            'totals' => $totals,
            'fallbacks' => CategoryService::FALLBACK_CODES,
        ]);
    }

    private function attempt(callable $action): void
    {
        try {
            $action();
        } catch (DomainException $e) {
            $this->addError('category', $e->getMessage());
        }
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
