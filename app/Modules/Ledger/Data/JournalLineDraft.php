<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Data;

use App\Modules\Ledger\Enums\EntryDirection;
use App\Modules\Shared\Domain\Money;

final readonly class JournalLineDraft
{
    public function __construct(
        public string $ledgerAccountId,
        public EntryDirection $direction,
        public Money $amount,
        public ?string $memo = null,
    ) {}

    public static function debit(string $ledgerAccountId, Money $amount, ?string $memo = null): self
    {
        return new self($ledgerAccountId, EntryDirection::DEBIT, $amount, $memo);
    }

    public static function credit(string $ledgerAccountId, Money $amount, ?string $memo = null): self
    {
        return new self($ledgerAccountId, EntryDirection::CREDIT, $amount, $memo);
    }
}
