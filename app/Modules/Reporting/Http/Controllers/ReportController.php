<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reporting\Services\FinancialSummaryService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReportController extends Controller
{
    public function __construct(private readonly FinancialSummaryService $summary) {}

    /** Defaults to the current calendar month in the display timezone. */
    public function summary(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'currency' => ['sometimes', 'string', 'size:3'],
        ]);

        $tz = (string) config('finance.display_timezone');
        $now = CarbonImmutable::now($tz);
        $from = isset($data['date_from']) ? CarbonImmutable::createFromFormat('Y-m-d', $data['date_from'], $tz) : $now->startOfMonth();
        $to = isset($data['date_to']) ? CarbonImmutable::createFromFormat('Y-m-d', $data['date_to'], $tz) : $now;

        $summary = $this->summary->summarize(
            $request->user(),
            $from,
            $to,
            strtoupper($data['currency'] ?? (string) config('finance.default_currency')),
        );

        return response()->json(['data' => $summary]);
    }
}
