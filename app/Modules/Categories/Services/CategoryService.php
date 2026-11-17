<?php

declare(strict_types=1);

namespace App\Modules\Categories\Services;

use App\Models\User;
use App\Modules\Categories\Enums\CategoryKind;
use App\Modules\Categories\Exceptions\CategoryNotUsableException;
use App\Modules\Categories\Models\TransactionCategory;
use App\Modules\Ledger\Services\ChartOfAccountsService;
use Illuminate\Support\Facades\DB;

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
