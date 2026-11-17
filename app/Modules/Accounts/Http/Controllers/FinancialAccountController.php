<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounts\Http\Requests\StoreFinancialAccountRequest;
use App\Modules\Accounts\Http\Resources\FinancialAccountResource;
use App\Modules\Accounts\Models\FinancialAccount;
use App\Modules\Accounts\Services\FinancialAccountService;
use App\Modules\Counterparties\Services\CounterpartyService;
use App\Modules\Shared\Domain\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class FinancialAccountController extends Controller
{
    public function __construct(
        private readonly FinancialAccountService $accounts,
        private readonly CounterpartyService $counterparties,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $accounts = FinancialAccount::query()
            ->where('user_id', $request->user()->id)
            ->with(['ledgerAccount', 'institution', 'counterparty'])
            ->orderBy('name')
            ->get();

        return FinancialAccountResource::collection($accounts);
    }

    public function show(string $id): FinancialAccountResource
    {
        $account = FinancialAccount::query()->with(['ledgerAccount', 'institution', 'counterparty'])->findOrFail($id);
        Gate::authorize('view', $account);

        return new FinancialAccountResource($account);
    }

    public function store(StoreFinancialAccountRequest $request): JsonResponse
    {
        $data = $request->validated();
        $currency = (string) ($data['currency'] ?? config('finance.default_currency'));
        $opening = isset($data['opening_balance']) ? Money::of($data['opening_balance'], $currency) : Money::zero($currency);

        $account = DB::transaction(fn (): FinancialAccount => $this->accounts->open(
            user: $request->user(),
            name: $data['name'],
            accountTypeCode: $data['account_type'],
            institutionCode: $data['institution_code'] ?? null,
            openingBalance: $opening,
            openingBalanceDate: isset($data['opening_balance_date'])
                ? CarbonImmutable::createFromFormat('Y-m-d', $data['opening_balance_date'], (string) config('finance.display_timezone'))
                : null,
            accountNumberLast4: $data['account_number_last4'] ?? null,
            allowNegativeBalance: isset($data['allow_negative_balance']) ? (bool) $data['allow_negative_balance'] : null,
            aliases: $data['aliases'] ?? null,
            counterpartyId: $this->counterparties->resolve($request->user(), $data['counterparty'] ?? null)?->id,
        ));

        return (new FinancialAccountResource($account->fresh(['ledgerAccount', 'institution', 'counterparty'])))->response()->setStatusCode(201);
    }
}
