<?php

declare(strict_types=1);

namespace App\Modules\Categories\Services;

use App\Models\User;
use App\Modules\Categories\Enums\CategoryKind;
use App\Modules\Categories\Exceptions\CategoryNotUsableException;
use App\Modules\Categories\Models\TransactionCategory;
use App\Modules\Ledger\Services\ChartOfAccountsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CategoryService
{
    /**
     * Default chart of income/expense categories (Bahasa Indonesia names).
     * code => [kind, ledger code, display name]
     *
     * @var array<string, array{0: CategoryKind, 1: string, 2: string}>
     */
    public const array DEFAULTS = [
        'SALARY' => [CategoryKind::INCOME, '4100', 'Gaji'],
        'FREELANCE' => [CategoryKind::INCOME, '4200', 'Freelance'],
        'BUSINESS' => [CategoryKind::INCOME, '4300', 'Pendapatan Usaha'],
        'INTEREST_INCOME' => [CategoryKind::INCOME, '4400', 'Bunga & Bagi Hasil'],
        'GIFT' => [CategoryKind::INCOME, '4500', 'Hadiah'],
        'REFUND' => [CategoryKind::INCOME, '4600', 'Refund'],
        'OTHER_INCOME' => [CategoryKind::INCOME, '4800', 'Pendapatan Lainnya'],
        'FOOD' => [CategoryKind::EXPENSE, '5100', 'Makanan & Minuman'],
        'TRANSPORTATION' => [CategoryKind::EXPENSE, '5150', 'Transportasi'],
        'ELECTRICITY' => [CategoryKind::EXPENSE, '5200', 'Listrik'],
        'INTERNET' => [CategoryKind::EXPENSE, '5250', 'Internet & Pulsa'],
        'EDUCATION' => [CategoryKind::EXPENSE, '5300', 'Pendidikan'],
        'HOSTING' => [CategoryKind::EXPENSE, '5350', 'Hosting & Server'],
        'AI_TOOLS' => [CategoryKind::EXPENSE, '5400', 'Langganan AI'],
        'HEALTHCARE' => [CategoryKind::EXPENSE, '5450', 'Kesehatan'],
        'SHOPPING' => [CategoryKind::EXPENSE, '5500', 'Belanja'],
        'INTEREST_FEES' => [CategoryKind::EXPENSE, '5600', 'Bunga & Biaya Bank'],
        'OTHER_EXPENSE' => [CategoryKind::EXPENSE, '5800', 'Pengeluaran Lainnya'],
    ];

    public function __construct(private readonly ChartOfAccountsService $chart) {}

    public function provisionDefaults(User $user, string $currency = 'IDR'): void
    {
        DB::transaction(function () use ($user, $currency): void {
            foreach (self::DEFAULTS as $code => [$kind, $ledgerCode, $name]) {
                $exists = TransactionCategory::query()->where('user_id', $user->id)->where('code', $code)->exists();
                if (! $exists) {
                    $this->create($user, $kind, $name, $code, $ledgerCode, true, $currency);
                }
            }
        });
    }

    public function create(
        User $user,
        CategoryKind $kind,
        string $name,
        string $code,
        ?string $ledgerCode = null,
        bool $isSystem = false,
        string $currency = 'IDR',
    ): TransactionCategory {
        return DB::transaction(function () use ($user, $kind, $name, $code, $ledgerCode, $isSystem, $currency): TransactionCategory {
            $ledger = $this->chart->createAccount($user, $kind->ledgerClass(), $name, $currency, $ledgerCode, $isSystem);

            return TransactionCategory::create([
                'user_id' => $user->id,
                'ledger_account_id' => $ledger->id,
                'code' => strtoupper($code),
                'name' => $name,
                'kind' => $kind,
                'is_system' => $isSystem,
                'is_active' => true,
            ]);
        });
    }

    /** Fallback buckets used when a client sends no category; they can never be deactivated. */
    public const array FALLBACK_CODES = ['OTHER_INCOME', 'OTHER_EXPENSE'];

    /** A user-defined category. The code is derived from the name and made unique per user. */
    public function createCustom(User $user, CategoryKind $kind, string $name, string $currency = 'IDR'): TransactionCategory
    {
        $name = trim($name);
        $this->assertNameFree($user, $kind, $name);

        $base = 'C_'.(Str::of($name)->ascii()->upper()->replaceMatches('/[^A-Z0-9]+/', '_')->trim('_')->limit(36, '')->toString() ?: 'CATEGORY');
        $code = $base;
        for ($i = 2; TransactionCategory::query()->where('user_id', $user->id)->where('code', $code)->exists(); $i++) {
            $code = $base.'_'.$i;
        }

        return $this->create($user, $kind, $name, $code, null, false, $currency);
    }

    /** Rename the display name (category + its ledger account). The code and the history stay. */
    public function rename(User $user, string $categoryId, string $name): TransactionCategory
    {
        $category = $this->owned($user, $categoryId);
        $name = trim($name);
        $this->assertNameFree($user, $category->kind, $name, $category->id);

        return DB::transaction(function () use ($category, $name): TransactionCategory {
            $category->update(['name' => $name]);
            $category->ledgerAccount()->update(['name' => $name]);

            return $category;
        });
    }

    /**
     * Hide a category from new entries (or show it again). Its ledger account stays ACTIVE so past
     * entries keep reporting and can still be reversed.
     */
    public function setActive(User $user, string $categoryId, bool $active): TransactionCategory
    {
        $category = $this->owned($user, $categoryId);

        if (! $active && in_array($category->code, self::FALLBACK_CODES, true)) {
            throw new CategoryNotUsableException(__('ledger.category_fallback_required'));
        }

        $category->update(['is_active' => $active]);

        return $category;
    }

    private function owned(User $user, string $categoryId): TransactionCategory
    {
        return TransactionCategory::query()->where('user_id', $user->id)->whereKey($categoryId)->first()
            ?? throw new CategoryNotUsableException(__('ledger.category_not_found'));
    }

    private function assertNameFree(User $user, CategoryKind $kind, string $name, ?string $exceptId = null): void
    {
        if ($name === '' || mb_strlen($name) > 128) {
            throw new CategoryNotUsableException(__('ledger.category_name_invalid'));
        }

        $taken = TransactionCategory::query()
            ->where('user_id', $user->id)
            ->where('kind', $kind->value)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($exceptId, static fn ($q, string $id) => $q->whereKeyNot($id))
            ->exists();

        if ($taken) {
            throw new CategoryNotUsableException(__('ledger.category_name_taken', ['name' => $name]));
        }
    }

    /**
     * Resolve the category used for posting. Without an ID, uses $fallbackCode (e.g. a category suggested
     * from the description) when it exists and is active, else OTHER_INCOME / OTHER_EXPENSE.
     * Lookups are tenant-scoped: another user's category ID behaves exactly like a missing one.
     */
    public function resolveForPosting(User $user, ?string $categoryId, CategoryKind $kind, ?string $fallbackCode = null): TransactionCategory
    {
        $query = static fn () => TransactionCategory::query()->with('ledgerAccount')->where('user_id', $user->id);

        if ($categoryId === null && $fallbackCode !== null) {
            $suggested = $query()->where('code', $fallbackCode)->where('kind', $kind->value)->where('is_active', true)->first();
            if ($suggested !== null) {
                return $suggested;
            }
        }

        $category = $categoryId !== null
            ? $query()->whereKey($categoryId)->first()
            : $query()->where('code', $kind === CategoryKind::INCOME ? 'OTHER_INCOME' : 'OTHER_EXPENSE')->first();

        if ($category === null || ! $category->is_active) {
            throw new CategoryNotUsableException(__('ledger.category_not_found'));
        }

        if ($category->kind !== $kind) {
            throw new CategoryNotUsableException(__('ledger.category_wrong_kind', ['kind' => $kind->value]));
        }

        return $category;
    }
}
