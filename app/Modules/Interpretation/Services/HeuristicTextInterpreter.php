<?php

declare(strict_types=1);

namespace App\Modules\Interpretation\Services;

use App\Modules\Interpretation\Contracts\TextInterpreter;
use App\Modules\Interpretation\Data\Interpretation;
use App\Modules\Interpretation\Enums\Confidence;
use App\Modules\Interpretation\Enums\TransactionIntent;

/** Local, deterministic implementation of TextInterpreter. No data leaves the server. */
final class HeuristicTextInterpreter implements TextInterpreter
{
    public function __construct(private readonly TextAnalyzer $text) {}

    public function interpret(string $text, string $currency = 'IDR'): Interpretation
    {
        $detected = $this->text->detect($text);
        $intent = $detected['intent'];

        $incoming = $intent === null ? null : ($intent->isIncome() || $intent === TransactionIntent::LOAN_RECEIVED);
        $counterparty = in_array($intent, [TransactionIntent::INTERNAL_TRANSFER], true)
            ? null
            : $this->text->counterparty($text, $incoming);

        return new Interpretation(
            intent: $intent,
            confidence: $intent !== null ? Confidence::MEDIUM : Confidence::LOW,
            counterparty: $counterparty,
            activity: $intent === TransactionIntent::ACTIVITY_INCOME ? $this->text->activity($text) : null,
            categoryCode: $detected['category'],
            amount: $this->text->amount($text, $currency),
            signals: $detected['keyword'] !== null ? ['keyword:'.$detected['keyword']] : [],
            warnings: array_values(array_filter([$detected['warning'], $intent === null && $detected['warning'] === null ? 'INTENT_UNKNOWN' : null])),
        );
    }
}
