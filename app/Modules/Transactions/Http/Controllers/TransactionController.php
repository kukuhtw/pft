<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Transactions\Enums\TransactionStatus;
use App\Modules\Transactions\Enums\TransactionType;
use App\Modules\Transactions\Http\Requests\ExpenseRequest;
use App\Modules\Transactions\Http\Requests\IncomeRequest;
use App\Modules\Transactions\Http\Requests\TransferRequest;
use App\Modules\Transactions\Http\Resources\TransactionResource;
use App\Modules\Transactions\Models\Transaction;
use App\Modules\Transactions\Services\TransactionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class TransactionController extends Controller
{
    public function __construct(private readonly TransactionService $transactions) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'type' => ['sometimes', Rule::enum(TransactionType::class)],
            'status' => ['sometimes', Rule::enum(TransactionStatus::class)],
            'account_id' => ['sometimes', 'string', 'ulid'],
            'category_id' => ['sometimes', 'string', 'ulid'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = Transaction::query()
            ->where('user_id', $request->user()->id)
            ->with(['sourceAccount', 'destinationAccount', 'category', 'merchant'])
            ->when($filters['date_from'] ?? null, static fn (Builder $q, string $d) => $q->where('transaction_date', '>=', $d))
            ->when($filters['date_to'] ?? null, static fn (Builder $q, string $d) => $q->where('transaction_date', '<=', $d))
            ->when($filters['type'] ?? null, static fn (Builder $q, string $t) => $q->where('type', $t))
            ->when($filters['status'] ?? null, static fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($filters['category_id'] ?? null, static fn (Builder $q, string $c) => $q->where('category_id', $c))
            ->when($filters['account_id'] ?? null, static fn (Builder $q, string $a) => $q->where(
                static fn (Builder $w) => $w->where('source_account_id', $a)->orWhere('destination_account_id', $a),
            ))
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return TransactionResource::collection($page);
    }

    public function show(Request $request, string $id): TransactionResource
    {
        $transaction = Transaction::query()->findOrFail($id);
        Gate::authorize('view', $transaction);

        return new TransactionResource($transaction->load([
            'sourceAccount', 'destinationAccount', 'category', 'merchant', 'journalEntries.lines',
        ]));
    }

    public function income(IncomeRequest $request): JsonResponse
    {
        return $this->respond($this->transactions->recordIncome($request->user(), $request->toInput()));
    }

    public function expense(ExpenseRequest $request): JsonResponse
    {
        return $this->respond($this->transactions->recordExpense($request->user(), $request->toInput()));
    }

    public function transfer(TransferRequest $request): JsonResponse
    {
        return $this->respond($this->transactions->recordTransfer($request->user(), $request->toInput()));
    }

    public function reverse(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:255']]);

        $transaction = Transaction::query()->findOrFail($id);
        Gate::authorize('reverse', $transaction);

        $reversed = $this->transactions->reverse(
            $request->user(),
            $transaction->id,
            $data['reason'] ?? null,
            $request->header('Idempotency-Key'),
        );

        return (new TransactionResource($reversed->load('journalEntries.lines')))->response()->setStatusCode(200);
    }

    /** 201 for a newly posted transaction, 200 + Idempotent-Replayed header for a replay. */
    private function respond(Transaction $transaction): JsonResponse
    {
        $replayed = ! $transaction->wasRecentlyCreated;

        $response = (new TransactionResource($transaction->loadMissing(['sourceAccount', 'destinationAccount', 'category', 'merchant'])))
            ->response()
            ->setStatusCode($replayed ? 200 : 201);

        if ($replayed) {
            $response->headers->set('Idempotent-Replayed', 'true');
        }

        return $response;
    }
}
