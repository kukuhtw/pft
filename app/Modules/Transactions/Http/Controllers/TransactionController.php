<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Debts\Services\DebtService;
use App\Modules\Interpretation\Enums\TransactionIntent;
use App\Modules\Interpretation\Services\EntryInterpretationService;
use App\Modules\Transactions\Enums\TransactionStatus;
use App\Modules\Transactions\Enums\TransactionType;
use App\Modules\Transactions\Http\Requests\DebtPaymentRequest;
use App\Modules\Transactions\Http\Requests\ExpenseRequest;
use App\Modules\Transactions\Http\Requests\IncomeRequest;
use App\Modules\Transactions\Http\Requests\LoanReceivedRequest;
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
    public function __construct(
        private readonly TransactionService $transactions,
        private readonly DebtService $debts,
        private readonly EntryInterpretationService $interpreter,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'type' => ['sometimes', Rule::enum(TransactionType::class)],
            'status' => ['sometimes', Rule::enum(TransactionStatus::class)],
            'account_id' => ['sometimes', 'string', 'ulid'],
            'category_id' => ['sometimes', 'string', 'ulid'],
            'intent' => ['sometimes', Rule::enum(TransactionIntent::class)],
            'counterparty_id' => ['sometimes', 'string', 'ulid'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = Transaction::query()
            ->where('user_id', $request->user()->id)
            ->with(['sourceAccount', 'destinationAccount', 'category', 'merchant', 'counterparty'])
            ->when($filters['intent'] ?? null, static fn (Builder $q, string $i) => $q->where('intent', $i))
            ->when($filters['counterparty_id'] ?? null, static fn (Builder $q, string $c) => $q->where('counterparty_id', $c))
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
            'sourceAccount', 'destinationAccount', 'category', 'merchant', 'counterparty', 'journalEntries.lines',
        ]));
    }

    /** Pinjaman diterima dari pihak tertentu (LIABILITY_DRAWDOWN). */
    public function loanReceived(LoanReceivedRequest $request): JsonResponse
    {
        return $this->respond($this->debts->loanReceived($request->user(), $request->toInput()));
    }

    /** Pembayaran ke pihak tertentu atas hutang tertentu (LIABILITY_PAYMENT). */
    public function debtPayment(DebtPaymentRequest $request): JsonResponse
    {
        return $this->respond($this->debts->debtPayment($request->user(), $request->toInput()));
    }

    /** Free text → confirmable proposal. Read-only; the text is neither stored nor logged. */
    public function interpret(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:500'],
            'currency' => ['sometimes', 'string', 'size:3', Rule::exists('currencies', 'code')->where('is_active', true)],
        ]);

        return response()->json(['data' => $this->interpreter->interpret(
            $request->user(),
            $data['text'],
            (string) ($data['currency'] ?? config('finance.default_currency')),
        )]);
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

        $response = (new TransactionResource($transaction->loadMissing(['sourceAccount', 'destinationAccount', 'category', 'merchant', 'counterparty'])))
            ->response()
            ->setStatusCode($replayed ? 200 : 201);

        if ($replayed) {
            $response->headers->set('Idempotent-Replayed', 'true');
        }

        return $response;
    }
}
