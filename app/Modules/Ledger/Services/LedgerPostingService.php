<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Services;

use App\Modules\Ledger\Data\JournalDraft;
use App\Modules\Ledger\Data\JournalLineDraft;
use App\Modules\Ledger\Enums\EntryDirection;
use App\Modules\Ledger\Enums\JournalEntryType;
use App\Modules\Ledger\Enums\LedgerAccountStatus;
use App\Modules\Ledger\Exceptions\InvalidJournalException;
use App\Modules\Ledger\Exceptions\LedgerAccountNotUsableException;
use App\Modules\Ledger\Exceptions\UnbalancedJournalException;
use App\Modules\Ledger\Models\JournalEntry;
use App\Modules\Ledger\Models\JournalLine;
use App\Modules\Ledger\Models\LedgerAccount;
use App\Modules\Shared\Domain\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The single write path into the double-entry ledger.
 *
 * Guarantees (all inside one database transaction):
 *  - Σ debit === Σ credit, every line amount > 0, single currency;
 *  - every ledger account exists, is ACTIVE, belongs to the entry owner and matches currency;
 *  - touched ledger accounts are locked (SELECT … FOR UPDATE) in deterministic order;
 *  - the balance projection is updated atomically with the journal lines.
 */
final class LedgerPostingService
{
    public const int MAX_LINES = 200;

    public function post(JournalDraft $draft): JournalEntry
    {
        [$totalDebit, $totalCredit] = $this->validateShape($draft);

        return DB::transaction(function () use ($draft, $totalDebit, $totalCredit): JournalEntry {
            $accounts = $this->lockAccounts($draft);
            $now = CarbonImmutable::now();

            $entry = JournalEntry::create([
                'user_id' => $draft->userId,
                'transaction_id' => $draft->transactionId,
                'entry_type' => $draft->entryType,
                'reverses_entry_id' => $draft->reversesEntryId,
                'entry_date' => $draft->entryDate->toDateString(),
                'description' => $draft->description,
                'currency_code' => $draft->currency,
                'total_debit' => $totalDebit->amount,
                'total_credit' => $totalCredit->amount,
                'posted_by' => $draft->postedBy,
                'posted_at' => $now,
            ]);

            $rows = [];
            /** @var array<string, Money> $deltas signed change in each account's normal-balance sign */
            $deltas = [];

            foreach ($draft->lines as $index => $line) {
                $rows[] = [
                    'journal_entry_id' => $entry->id,
                    'user_id' => $draft->userId,
                    'ledger_account_id' => $line->ledgerAccountId,
                    'line_no' => $index + 1,
                    'direction' => $line->direction->value,
                    'amount' => $line->amount->amount,
                    'memo' => $line->memo,
                    'created_at' => $now,
                ];

                $account = $accounts[$line->ledgerAccountId];
                $signed = $line->direction === $account->class->normalBalance() ? $line->amount : $line->amount->negate();
                $deltas[$line->ledgerAccountId] = ($deltas[$line->ledgerAccountId] ?? Money::zero($draft->currency))->plus($signed);
            }

            JournalLine::query()->insert($rows);

            foreach ($deltas as $accountId => $delta) {
                $newBalance = $accounts[$accountId]->balanceMoney()->plus($delta);
                LedgerAccount::query()->whereKey($accountId)->update([
                    'balance' => $newBalance->amount,
                    'updated_at' => $now,
                ]);
            }

            return $entry->load('lines');
        });
    }

    /**
     * Post the mirror image of an existing entry. The reversal keeps the original entry_date by default
     * so period reports net to zero, while `posted_at` records when the correction happened.
     */
    public function reverse(JournalEntry $original, int $postedBy, ?string $reason = null, ?CarbonImmutable $entryDate = null): JournalEntry
    {
        return DB::transaction(function () use ($original, $postedBy, $reason, $entryDate): JournalEntry {
            /** @var JournalEntry $locked */
            $locked = JournalEntry::query()->whereKey($original->id)->lockForUpdate()->firstOrFail();

            if ($locked->entry_type === JournalEntryType::REVERSAL) {
                throw new InvalidJournalException(__('ledger.cannot_reverse_reversal'));
            }

            if (JournalEntry::query()->where('reverses_entry_id', $locked->id)->exists()) {
                throw new InvalidJournalException(__('ledger.already_reversed'));
            }

            $lines = $locked->lines()->get()->map(static fn (JournalLine $line): JournalLineDraft => new JournalLineDraft(
                ledgerAccountId: $line->ledger_account_id,
                direction: $line->direction->opposite(),
                amount: Money::fromDatabase($line->getRawOriginal('amount'), $locked->currency_code),
                memo: $line->memo,
            ))->all();

            return $this->post(new JournalDraft(
                userId: $locked->user_id,
                postedBy: $postedBy,
                entryDate: $entryDate ?? CarbonImmutable::parse($locked->entry_date),
                currency: $locked->currency_code,
                lines: $lines,
                entryType: JournalEntryType::REVERSAL,
                transactionId: $locked->transaction_id,
                description: mb_substr('Reversal: '.($reason ?? $locked->description ?? $locked->id), 0, 255),
                reversesEntryId: $locked->id,
            ));
        });
    }

    /**
     * @return array{0: Money, 1: Money}
     */
    private function validateShape(JournalDraft $draft): array
    {
        $count = count($draft->lines);

        if ($count < 2) {
            throw new InvalidJournalException(__('ledger.min_lines'));
        }

        if ($count > self::MAX_LINES) {
            throw new InvalidJournalException(__('ledger.max_lines', ['max' => self::MAX_LINES]));
        }

        $debit = Money::zero($draft->currency);
        $credit = Money::zero($draft->currency);

        foreach ($draft->lines as $line) {
            if (! $line instanceof JournalLineDraft) {
                throw new InvalidJournalException(__('ledger.invalid_line'));
            }

            if ($line->amount->currency !== $draft->currency) {
                throw new InvalidJournalException(__('ledger.currency_mismatch'));
            }

            if (! $line->amount->isPositive()) {
                throw new InvalidJournalException(__('ledger.non_positive_line'));
            }

            if ($line->direction === EntryDirection::DEBIT) {
                $debit = $debit->plus($line->amount);
            } else {
                $credit = $credit->plus($line->amount);
            }
        }

        if (! $debit->equals($credit)) {
            throw UnbalancedJournalException::totals($debit->amount, $credit->amount);
        }

        return [$debit, $credit];
    }

    /**
     * @return Collection<string, LedgerAccount>
     */
    private function lockAccounts(JournalDraft $draft): Collection
    {
        $ids = array_values(array_unique(array_map(static fn (JournalLineDraft $l): string => $l->ledgerAccountId, $draft->lines)));
        sort($ids, SORT_STRING);

        $accounts = LedgerAccount::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($ids as $id) {
            /** @var LedgerAccount|null $account */
            $account = $accounts->get($id);

            if ($account === null
                || (int) $account->user_id !== $draft->userId
                || $account->status !== LedgerAccountStatus::ACTIVE
                || $account->currency_code !== $draft->currency) {
                throw new LedgerAccountNotUsableException(__('ledger.account_not_usable'));
            }
        }

        return $accounts;
    }
}
