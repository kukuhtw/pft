<?php

declare(strict_types=1);

namespace App\Modules\Interpretation\Services;

use App\Modules\Accounts\Models\FinancialAccount;
use App\Modules\Categories\Models\TransactionCategory;
use App\Modules\Interpretation\Data\Interpretation;
use App\Modules\Interpretation\Enums\Confidence;
use App\Modules\Interpretation\Enums\TransactionIntent;
use App\Modules\Transactions\Data\TransactionInput;
use App\Modules\Transactions\Enums\TransactionType;

/**
 * Classifies an entry *at posting time*. Deterministic by design: the intent comes from the ledger
 * structure (account classes + category), never from free text alone, so a misread description can
 * never change what the books say. Text is used only to
 *   - pick the income category when the client sent none,
 *   - fill counterparty / activity when not given explicitly,
 *   - raise warnings when the text contradicts the chosen operation (e.g. "pinjam dari Budi" posted as income).
 *
 * This runs inside the posting DB transaction, so it must stay local and fast (no LLM / network calls).
 */
final class PostingClassifier
{
    /** Category codes whose income counts as "earned by an activity". */
    public const array ACTIVITY_CATEGORIES = ['FREELANCE', 'BUSINESS'];

    public function __construct(private readonly TextAnalyzer $text) {}

    /** Category code to use for an income entry posted without category_id. */
    public function suggestIncomeCategory(?string $description): ?string
    {
        $detected = $this->text->detect($description);

        return $detected['intent']?->isIncome() ? $detected['category'] : null;
    }

    public function classify(
        TransactionType $type,
        TransactionInput $input,
        ?FinancialAccount $source,
        ?FinancialAccount $destination,
        ?TransactionCategory $category,
        bool $categoryFromText = false,
    ): Interpretation {
        $detected = $this->text->detect($input->description);
        $signals = ['type:'.$type->value];
        $warnings = [];

        if ($detected['keyword'] !== null) {
            $signals[] = 'keyword:'.$detected['keyword'];
        }
        if ($detected['warning'] !== null) {
            $warnings[] = $detected['warning'];
        }

        [$intent, $confidence] = match ($type) {
            TransactionType::INCOME => $this->incomeIntent($category, $detected['intent'], $categoryFromText),
            TransactionType::EXPENSE => [TransactionIntent::EXPENSE, Confidence::HIGH],
            TransactionType::LIABILITY_DRAWDOWN => [TransactionIntent::LOAN_RECEIVED, Confidence::HIGH],
            TransactionType::LIABILITY_PAYMENT => [TransactionIntent::DEBT_REPAYMENT, Confidence::HIGH],
            TransactionType::INTERNAL_TRANSFER => [TransactionIntent::INTERNAL_TRANSFER, Confidence::HIGH],
            TransactionType::ADJUSTMENT => [TransactionIntent::OPENING_BALANCE, Confidence::HIGH],
        };

        if ($category !== null) {
            $signals[] = 'category:'.$category->code;
        }

        // The text says "loan"/"debt" but the operation does not touch a liability: the entry would
        // inflate income (loan posted as income) or expenses (repayment posted as expense).
        $textIntent = $detected['intent'];
        if ($textIntent === TransactionIntent::LOAN_RECEIVED && $type !== TransactionType::LIABILITY_DRAWDOWN) {
            $warnings[] = 'TEXT_SUGGESTS_LOAN_RECEIVED';
        }
        if ($textIntent === TransactionIntent::DEBT_REPAYMENT && $type !== TransactionType::LIABILITY_PAYMENT) {
            $warnings[] = 'TEXT_SUGGESTS_DEBT_REPAYMENT';
        }

        $debtAccount = match ($type) {
            TransactionType::LIABILITY_DRAWDOWN => $source,
            TransactionType::LIABILITY_PAYMENT => $destination,
            default => null,
        };
        if ($debtAccount !== null) {
            $signals[] = 'debt_account:'.$debtAccount->account_type_code;
        }

        $counterparty = $this->counterparty($input, $debtAccount, $intent, $signals);

        $activity = $input->activity;
        if ($activity === null && $intent === TransactionIntent::ACTIVITY_INCOME) {
            $activity = $this->text->activity($input->description) ?? $category?->name;
        }

        return new Interpretation(
            intent: $intent,
            confidence: $confidence,
            counterparty: $counterparty,
            activity: $activity !== null ? mb_substr($activity, 0, 64) : null,
            categoryCode: $category?->code,
            amount: $input->amount,
            signals: array_values(array_unique($signals)),
            warnings: array_values(array_unique($warnings)),
        );
    }

    /**
     * @return array{0: TransactionIntent, 1: Confidence}
     */
    private function incomeIntent(?TransactionCategory $category, ?TransactionIntent $textIntent, bool $categoryFromText): array
    {
        $code = $category?->code;
        $structural = $categoryFromText ? Confidence::MEDIUM : Confidence::HIGH;

        if ($code === 'SALARY') {
            return [TransactionIntent::SALARY_INCOME, $structural];
        }

        if (in_array($code, self::ACTIVITY_CATEGORIES, true)) {
            return [TransactionIntent::ACTIVITY_INCOME, $structural];
        }

        // Generic or user-defined category: let the text refine salary vs activity.
        $refinable = $code === 'OTHER_INCOME' || $category?->is_system === false;
        if ($refinable && $textIntent !== null && $textIntent->isIncome() && $textIntent !== TransactionIntent::OTHER_INCOME) {
            return [$textIntent, Confidence::MEDIUM];
        }

        return [TransactionIntent::OTHER_INCOME, $refinable ? Confidence::LOW : $structural];
    }

    /**
     * Explicit input > the party the debt account is linked to > text ("dari X" / "ke X").
     *
     * @param  list<string>  $signals
     */
    private function counterparty(TransactionInput $input, ?FinancialAccount $debtAccount, ?TransactionIntent $intent, array &$signals): ?string
    {
        if ($input->counterpartyName !== null && trim($input->counterpartyName) !== '') {
            $signals[] = 'counterparty:explicit';

            return trim($input->counterpartyName);
        }

        if ($debtAccount !== null) {
            $debtAccount->loadMissing(['counterparty', 'institution']);
            $name = $debtAccount->counterparty->name ?? $debtAccount->institution?->name;
            if ($name !== null) {
                $signals[] = 'counterparty:debt_account';

                return $name;
            }
        }

        if (in_array($intent, [TransactionIntent::INTERNAL_TRANSFER, TransactionIntent::OPENING_BALANCE], true)) {
            return null; // the other side is the user's own account
        }

        $incoming = $intent === null ? null : ($intent->isIncome() || $intent === TransactionIntent::LOAN_RECEIVED);
        $name = $this->text->counterparty($input->description, $incoming);
        if ($name !== null) {
            $signals[] = 'counterparty:text';
        }

        return $name;
    }
}
