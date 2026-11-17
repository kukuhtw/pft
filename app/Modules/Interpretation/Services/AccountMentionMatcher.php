<?php

declare(strict_types=1);

namespace App\Modules\Interpretation\Services;

use App\Modules\Accounts\Models\FinancialAccount;
use Illuminate\Support\Collection;

/**
 * Finds which of the user's own accounts a sentence names ("makan 45rb pakai gopay",
 * "transfer BCA ke BNI 2jt"). Only unambiguous matches count: if two accounts fit the same
 * word (e.g. two BCA accounts), nothing is picked and the user is asked instead.
 */
final class AccountMentionMatcher
{
    /** Words meaning physical cash, matched to the user's single cash account. */
    private const array CASH_WORDS = ['tunai', 'cash', 'kas', 'dompet', 'uang tunai'];

    /**
     * @param  Collection<int, FinancialAccount>  $accounts  candidates (already owner-scoped)
     * @return list<string> account ids in the order they appear in the text
     */
    public function find(string $text, Collection $accounts): array
    {
        $haystack = ' '.self::normalize($text).' ';
        $byPosition = [];

        // Pass 1: the account's own name or aliases (most specific).
        foreach ($accounts as $account) {
            $terms = [$account->name, ...array_map('trim', explode(',', (string) $account->aliases))];
            $pos = $this->firstPosition($haystack, $terms);
            if ($pos !== null) {
                $byPosition[$pos][] = $account->id;
            }
        }

        $named = array_merge(...array_values($byPosition ?: [[]]));

        // Pass 2: bank / e-wallet name or code, and cash words, for accounts not named directly.
        foreach ($accounts as $account) {
            if (in_array($account->id, $named, true)) {
                continue;
            }
            $terms = $account->institution !== null ? [$account->institution->code, $account->institution->name] : [];
            if ($account->account_type_code === 'CASH') {
                $terms = [...$terms, ...self::CASH_WORDS];
            }
            $pos = $this->firstPosition($haystack, $terms);
            if ($pos !== null) {
                $byPosition[$pos][] = $account->id;
            }
        }

        ksort($byPosition);
        $ids = [];
        foreach ($byPosition as $candidates) {
            $candidates = array_values(array_unique($candidates));
            // Two accounts fit the same word: ambiguous, let the user choose.
            if (count($candidates) === 1 && ! in_array($candidates[0], $ids, true)) {
                $ids[] = $candidates[0];
            }
        }

        return $ids;
    }

    /** @param  list<string>  $terms */
    private function firstPosition(string $haystack, array $terms): ?int
    {
        $best = null;
        foreach ($terms as $term) {
            $term = self::normalize((string) $term);
            if (mb_strlen($term) < 2) {
                continue;
            }
            $pos = mb_strpos($haystack, ' '.$term.' ');
            if ($pos !== false && ($best === null || $pos < $best)) {
                $best = $pos;
            }
        }

        return $best;
    }

    private static function normalize(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($value))));
    }
}
