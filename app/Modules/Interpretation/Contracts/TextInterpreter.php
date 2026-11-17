<?php

declare(strict_types=1);

namespace App\Modules\Interpretation\Contracts;

use App\Modules\Interpretation\Data\Interpretation;

/**
 * Turns a free-text entry ("pinjam dari Pak Budi 2jt") into an Interpretation *proposal*. Nothing is posted:
 * the client shows the proposal, the user confirms, and the confirmed structured fields are posted.
 *
 * The default binding is the local HeuristicTextInterpreter. An LLM-backed implementation may be bound
 * instead; it must return Interpretation::jsonSchema()-shaped data, and — because the text is personal
 * financial data — it must only be enabled with the user's consent and a vetted processor
 * (ISO/IEC 27001 A.5.34 / A.8.11). Posting never depends on this interface (see PostingClassifier).
 */
interface TextInterpreter
{
    public function interpret(string $text, string $currency = 'IDR'): Interpretation;
}
