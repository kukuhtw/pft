<?php

declare(strict_types=1);

namespace App\Modules\Interpretation\Data;

use App\Modules\Interpretation\Enums\Confidence;
use App\Modules\Interpretation\Enums\TransactionIntent;
use App\Modules\Shared\Domain\Money;

/**
 * The app's understanding of an entry. Produced by the heuristic interpreter today; an LLM-backed
 * interpreter must return exactly this shape (see jsonSchema()).
 */
final readonly class Interpretation
{
    /**
     * @param  list<string>  $signals  machine-readable reasons, e.g. "keyword:gaji", "account:LIABILITY"
     * @param  list<string>  $warnings  machine-readable codes, e.g. "TEXT_SUGGESTS_LOAN_NOT_INCOME"
     */
    public function __construct(
        public ?TransactionIntent $intent,
        public Confidence $confidence,
        public ?string $counterparty = null,
        public ?string $activity = null,
        public ?string $categoryCode = null,
        public ?Money $amount = null,
        public array $signals = [],
        public array $warnings = [],
        public string $source = 'heuristic',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'intent' => $this->intent?->value,
            'intent_label' => $this->intent?->label(),
            'operation' => $this->intent?->operation(),
            'confidence' => $this->confidence->value,
            'counterparty' => $this->counterparty,
            'activity' => $this->activity,
            'category_code' => $this->categoryCode,
            'amount' => $this->amount,
            'signals' => $this->signals,
            'warnings' => $this->warnings,
            'source' => $this->source,
        ];
    }

    /**
     * JSON Schema an LLM interpreter's structured output must satisfy. Keeping the enum list here means
     * the heuristic and LLM paths can never drift apart.
     *
     * @return array<string, mixed>
     */
    public static function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['intent', 'confidence', 'counterparty', 'activity', 'category_code', 'amount'],
            'properties' => [
                'intent' => ['type' => ['string', 'null'], 'enum' => [...array_column(TransactionIntent::cases(), 'value'), null]],
                'confidence' => ['type' => 'string', 'enum' => array_column(Confidence::cases(), 'value')],
                'counterparty' => ['type' => ['string', 'null'], 'maxLength' => 128, 'description' => 'Party money came from or went to'],
                'activity' => ['type' => ['string', 'null'], 'maxLength' => 64, 'description' => 'Activity that earned ACTIVITY_INCOME'],
                'category_code' => ['type' => ['string', 'null'], 'description' => 'Income/expense category code, e.g. SALARY, FREELANCE, BUSINESS'],
                'amount' => ['type' => ['string', 'null'], 'pattern' => '^\d{1,18}(\.\d{1,2})?$', 'description' => 'Decimal string, no float'],
            ],
        ];
    }
}
