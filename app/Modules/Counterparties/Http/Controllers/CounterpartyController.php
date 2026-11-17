<?php

declare(strict_types=1);

namespace App\Modules\Counterparties\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounts\Enums\AccountStatus;
use App\Modules\Accounts\Models\FinancialAccount;
use App\Modules\Counterparties\Models\Counterparty;
use App\Modules\Ledger\Enums\LedgerAccountClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CounterpartyController extends Controller
{
    /** The user's counterparties with their open debts (what is owed to whom). */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $debts = FinancialAccount::query()
            ->with('ledgerAccount')
            ->where('user_id', $userId)
            ->whereNotNull('counterparty_id')
            ->where('status', AccountStatus::ACTIVE)
            ->whereHas('ledgerAccount', static fn ($q) => $q->where('class', LedgerAccountClass::LIABILITY->value))
            ->get()
            ->groupBy('counterparty_id');

        $data = Counterparty::query()
            ->where('user_id', $userId)
            ->orderBy('name')
            ->get()
            ->map(static fn (Counterparty $c): array => [
                'id' => $c->id,
                'name' => $c->name,
                'kind' => $c->kind->value,
                'debt_accounts' => ($debts[$c->id] ?? collect())->map(static fn (FinancialAccount $a): array => [
                    'id' => $a->id,
                    'name' => $a->name,
                    'account_type' => $a->account_type_code,
                    'balance' => $a->balance(), // amount still owed
                ])->values()->all(),
            ]);

        return response()->json(['data' => $data]);
    }
}
