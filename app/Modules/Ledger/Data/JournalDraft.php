<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Data;

use App\Modules\Ledger\Enums\JournalEntryType;
use Carbon\CarbonImmutable;

/**
 * An unposted journal entry. Validation happens in LedgerPostingService::post().
 */
final readonly class JournalDraft
{
    /**
     * @param  list<JournalLineDraft>  $lines
     */
    public function __construct(
        public int $userId,
        public int $postedBy,
        public CarbonImmutable $entryDate,
        public string $currency,
        public array $lines,
        public JournalEntryType $entryType = JournalEntryType::STANDARD,
        public ?string $transactionId = null,
        public ?string $description = null,
        public ?string $reversesEntryId = null,
    ) {}
}
