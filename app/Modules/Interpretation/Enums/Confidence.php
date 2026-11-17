<?php

declare(strict_types=1);

namespace App\Modules\Interpretation\Enums;

enum Confidence: string
{
    /** Derived from ledger structure (account classes, explicit category, linked debt account). */
    case HIGH = 'HIGH';
    /** Derived from a keyword/pattern match in the text. */
    case MEDIUM = 'MEDIUM';
    /** Default/fallback: nothing matched. */
    case LOW = 'LOW';
}
