<?php

declare(strict_types=1);

namespace App\Modules\Categories\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Categories\Enums\CategoryKind;
use App\Modules\Categories\Models\TransactionCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['kind' => ['sometimes', Rule::enum(CategoryKind::class)]]);

        $categories = TransactionCategory::query()
            ->where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->when($filters['kind'] ?? null, static fn ($q, string $kind) => $q->where('kind', $kind))
            ->orderBy('kind')
            ->orderBy('name')
            ->get()
            ->map(static fn (TransactionCategory $c): array => [
                'id' => $c->id,
                'code' => $c->code,
                'name' => $c->name,
                'kind' => $c->kind->value,
                'is_system' => $c->is_system,
            ]);

        return response()->json(['data' => $categories]);
    }
}
