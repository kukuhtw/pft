<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Data;

use App\Modules\Shared\Domain\Money;
use Carbon\CarbonImmutable;
use JsonSerializable;

final readonly class FinancialSummary implements JsonSerializable
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public Money $totalIncome,
        public Money $totalExpenses,
        public Money $netIncome,
        public Money $totalInternalTransfers,
        public Money $totalAssets,
        public Money $totalLiabilities,
        public Money $netWorth,
        public Money $netCashFlow,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'period' => ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString()],
            'total_income' => $this->totalIncome,
            'total_expenses' => $this->totalExpenses,
            'net_income' => $this->netIncome,
            'total_internal_transfers' => $this->totalInternalTransfers,
            'total_assets' => $this->totalAssets,
            'total_liabilities' => $this->totalLiabilities,
            'net_worth' => $this->netWorth,
            'net_cash_flow' => $this->netCashFlow,
        ];
    }
}
