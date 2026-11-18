<?php

declare(strict_types=1);

namespace App\Modules\Interpretation\Services;

use App\Modules\Interpretation\Enums\TransactionIntent;
use App\Modules\Shared\Domain\Money;

/**
 * Deterministic, local (no network) pattern engine for Indonesian + English finance text.
 *
 *   "pinjam dari Pak Budi 2jt"            → LOAN_RECEIVED, counterparty "Pak Budi", amount 2000000
 *   "gaji November dari PT Maju Jaya"     → SALARY_INCOME, counterparty "PT Maju Jaya"
 *   "hasil jualan kue dari Bu Rina 500rb" → ACTIVITY_INCOME, activity "jualan kue", counterparty "Bu Rina"
 *   "bayar hutang ke Budi 1,5 juta"       → DEBT_REPAYMENT, counterparty "Budi", amount 1500000
 *
 * Rules are evaluated in order; the first match wins. More specific/dangerous meanings come first
 * ("bayar pinjaman" must be a repayment, not a loan; "bayar gaji" is an expense, not salary).
 */
final class TextAnalyzer
{
    /** Warning codes for meanings the ledger does not model yet (money lent to / collected from others). */
    public const string LENDING_OUT = 'LENDING_OUT_NOT_SUPPORTED';

    public const string RECEIVABLE_COLLECTED = 'RECEIVABLE_COLLECTION_NOT_SUPPORTED';

    private const string FREELANCE_ACTIVITY = '/\b(?:freelance|freelancer|proyek|projek|project|fee|honor|honorarium|komisi|jasa|endorse(?:ment)?|ngajar|mengajar|les|kursus|konsultasi|desain|design|royalti|affiliate|ojol|ngojek|narik)\b/iu';

    private const string BUSINESS_ACTIVITY = '/\b(?:jual|jualan|penjualan|dagang|dagangan|usaha|bisnis|omzet|omset|toko|warung|katering|catering|panen|sewa|sewaan|kos|kosan|kost|kontrakan|order|orderan|reseller|dropship)\b/iu';

    /** @var list<array{0: string, 1: TransactionIntent|string, 2: string|null}> [regex, intent|warning, category code] */
    private const array RULES = [
        // --- Not modelled yet: user lends money out / a borrower pays the user back (piutang). ---
        ['/\b(?:meminjamkan|minjemin|minjamin|pinjamkan|pinjemin|pinjamin|(?:kasih|ngasih|memberi|beri)\s+pinjam(?:an)?|dipinjam\s+(?:oleh|sama|sm)|lend|lent)\b/iu', self::LENDING_OUT, null],
        ['/\b(?:dibayar(?:in)?|dibalikin|dikembalikan|terima\s+(?:pembayaran|pelunasan|cicilan)\s+(?:hutang|utang|pinjaman)|bayar\s+(?:hutang|utang)(?:nya)?\s+ke\s+(?:saya|aku|gue|gw))\b/iu', self::RECEIVABLE_COLLECTED, null],

        // --- Debt repayment: must precede loan rules ("bayar pinjaman"). ---
        ['/\b(?:bayar|byr|lunasi|lunasin|melunasi|pelunasan|cicil|nyicil|angsur|setor)\s+(?:\S+\s+){0,2}?(?:hutang|utang|pinjaman|pinjol|kredit|cicilan|angsuran|kpr|kta|kartu\s+kredit|cc|paylater|tagihan\s+kartu)\b/iu', TransactionIntent::DEBT_REPAYMENT, null],
        ['/\b(?:cicilan|angsuran|pelunasan)\b/iu', TransactionIntent::DEBT_REPAYMENT, null],
        ['/\b(?:balikin|kembalikan|mengembalikan|ngembaliin|ngembalikin)\s+(?:uang|duit|pinjaman)\b/iu', TransactionIntent::DEBT_REPAYMENT, null],
        ['/\b(?:repay(?:ment)?|pay(?:ing)?\s+back|loan\s+payment|debt\s+payment|credit\s+card\s+payment)\b/iu', TransactionIntent::DEBT_REPAYMENT, null],

        // --- Loan received (pinjaman / hutang masuk). ---
        ['/\b(?:pinjam|pinjem|minjam|minjem|meminjam|ngutang|berhutang|berutang|hutang|utang|pinjaman|pinjol|dipinjami|dipinjamin|dipinjemin|kasbon|pencairan\s+(?:pinjaman|kta|kredit|kpr)|borrow(?:ed)?|loan)\b/iu', TransactionIntent::LOAN_RECEIVED, null],

        // --- Moving money between own accounts. ---
        // "transfer BCA ke BNI", "tf dari gopay ke bca": a bank/wallet on both sides (a person on either side is not internal).
        ['/\b(?:transfer|tf|trf|pindah(?:in)?)\s+(?:(?:uang|dana|saldo|duit)\s+)?(?:dari\s+)?(?:'.self::OWN_ACCOUNT_WORDS.')\s+ke\s+(?:'.self::OWN_ACCOUNT_WORDS.')\b/iu', TransactionIntent::INTERNAL_TRANSFER, null],
        // "transfer ke GoPay dari BCA"
        ['/\b(?:transfer|tf|trf|pindah(?:in)?)\s+(?:(?:uang|dana|saldo|duit)\s+)?ke\s+(?:'.self::OWN_ACCOUNT_WORDS.')\s+dari\s+(?:'.self::OWN_ACCOUNT_WORDS.')\b/iu', TransactionIntent::INTERNAL_TRANSFER, null],
        ['/\b(?:tarik\s+(?:tunai|cash)|setor\s+tunai|ambil\s+(?:uang|duit)\s+(?:di\s+)?atm|pindah(?:in)?\s+(?:dana|saldo|uang|duit)|top\s*up\s+(?:gopay|ovo|dana|shopeepay|e-?wallet)|ke\s+rekening\s+sendiri|antar\s+rekening)\b/iu', TransactionIntent::INTERNAL_TRANSFER, null],

        // --- Spending (before salary/activity: "bayar gaji karyawan", "beli bahan jualan"). ---
        ['/\b(?:bayar|byr|beli|belanja|jajan|ongkir|biaya|top\s*up|isi\s+(?:pulsa|bensin|token)|spent|bought|buy|paid)\b/iu', TransactionIntent::EXPENSE, null],

        // --- Salary. ---
        ['/\b(?:gaji|gajian|salary|payroll|upah|thr|tunjangan|lembur|overtime|bonus|insentif|take\s+home\s+pay|thp)\b/iu', TransactionIntent::SALARY_INCOME, 'SALARY'],

        // --- Income from a specific activity. ---
        [self::FREELANCE_ACTIVITY, TransactionIntent::ACTIVITY_INCOME, 'FREELANCE'],
        [self::BUSINESS_ACTIVITY, TransactionIntent::ACTIVITY_INCOME, 'BUSINESS'],

        // --- Other income. ---
        ['/\b(?:bunga\s+(?:tabungan|deposito|bank)|bagi\s+hasil|dividen|dividend|interest|kupon)\b/iu', TransactionIntent::OTHER_INCOME, 'INTEREST_INCOME'],
        ['/\b(?:refund|pengembalian\s+dana|cashback|reimburse(?:ment)?|klaim|rembes)\b/iu', TransactionIntent::OTHER_INCOME, 'REFUND'],
        ['/\b(?:hadiah|kado|angpao|angpau|dikasih|dikirimin|pemberian|gift|sumbangan)\b/iu', TransactionIntent::OTHER_INCOME, 'GIFT'],
        ['/\b(?:terima|diterima|dapat|dapet|masuk|pemasukan|income|received)\b/iu', TransactionIntent::OTHER_INCOME, 'OTHER_INCOME'],

        // --- Everyday spending nouns without a verb ("makan siang 45rb", "parkir 5rb").
        //     Last, so "hasil jualan kopi" or "dapat traktiran makan" stay income. ---
        ['/\b(?:makan|sarapan|ngopi|kopi|minum|jajan|parkir|bensin|tol|ojek|ojol|taksi|listrik|pdam|air\s+pam|internet|wifi|pulsa|kuota|tagihan|sewa|kos|kost|laundry|obat|dokter|belanjaan|groceries|lunch|dinner|breakfast|coffee|parking|fuel|rent|bill)\b/iu', TransactionIntent::EXPENSE, null],
    ];

    private const array INCOMING_PREPOSITIONS = ['dari', 'from', 'oleh', 'dr'];

    private const array OUTGOING_PREPOSITIONS = ['ke', 'kepada', 'kpd', 'to', 'buat', 'untuk', 'utk'];

    private const array NEUTRAL_PREPOSITIONS = ['sama', 'sm', 'ama', 'with'];

    /** Tokens that end a counterparty/activity phrase. */
    private const array STOP_WORDS = [
        'bulan', 'bln', 'tgl', 'tanggal', 'sebesar', 'senilai', 'sejumlah', 'total', 'rp', 'idr', 'via', 'lewat',
        'pakai', 'pake', 'pada', 'di', 'untuk', 'utk', 'buat', 'karena', 'krn', 'yang', 'yg', 'atas', 'sebagai', 'dan',
        'and', '&', 'hari', 'minggu', 'kemarin', 'kemaren', 'tadi', 'besok', 'sisa', 'ke', 'dari', 'dr', 'sama', 'sm',
        'rekening', 'rek', 'transfer', 'tf', 'cash', 'tunai', 'pinjaman', 'hutang', 'utang', 'gaji', 'cicilan',
        'angsuran', 'bayar', 'lunas', 'for', 'with', 'on', 'at', 'the', 'ini', 'itu', 'sudah', 'udah', 'dulu', 'lagi',
        'jt', 'juta', 'rb', 'ribu', 'k', 'periode', 'tahun', 'thn', 'masuk', 'diterima', 'saya', 'aku', 'gue', 'gw',
        'kita', 'kami', 'me', 'my', 'januari', 'februari', 'maret', 'april', 'mei', 'juni', 'juli', 'agustus',
        'september', 'oktober', 'november', 'desember', 'jan', 'feb', 'mar', 'apr', 'jun', 'jul', 'agu', 'agt', 'sep',
        'okt', 'nov', 'des', 'january', 'february', 'march', 'may', 'june', 'july', 'august', 'october', 'december',
    ];

    private const array ACTIVITY_EXTRA_STOPS = ['pt', 'cv', 'ud', 'tbk', 'hasil', 'pak', 'bu', 'bapak', 'ibu', 'mas', 'mbak', 'klien', 'client'];

    /** Words that introduce what a loan is for. */
    private const array PURPOSE_MARKERS = ['untuk', 'utk', 'buat', 'bwt', 'keperluan', 'guna', 'for'];

    /** Skipped right after a marker: "untuk keperluan …", "buat biaya …" keeps the real subject. */
    private const array PURPOSE_FILLERS = ['keperluan', 'kebutuhan', 'keperluannya', 'kebutuhannya'];

    /** Tokens that end a purpose phrase (the lender, the account, the amount or the date follow). */
    private const array PURPOSE_STOPS = [
        'dari', 'dr', 'oleh', 'sama', 'sm', 'ama', 'via', 'lewat', 'pakai', 'pake', 'masuk', 'ditransfer', 'transfer', 'tf',
        'tgl', 'tanggal', 'sebesar', 'senilai', 'sejumlah', 'rp', 'idr', 'jt', 'juta', 'rb', 'ribu', 'k', 'from', 'kemarin',
        'kemaren', 'tadi', 'cash', 'tunai', 'rekening', 'rek',
    ];

    private const array UPPERCASE_TOKENS = ['pt', 'cv', 'ud', 'pd', 'tbk', 'bca', 'bni', 'bri', 'bsi', 'cimb', 'ojk'];

    /** Banks, e-wallets and cash: words that name one of the user's own accounts. */
    private const string OWN_ACCOUNT_WORDS = 'bca|bni|bri|mandiri|bsi|cimb(?:\s+niaga)?|permata|danamon|btn|jago|bank\s+jago|seabank|blu|jenius|ocbc|maybank|gopay|ovo|dana|shopeepay|linkaja|e-?wallet|tunai|cash|dompet|rekening(?:\s+\w+)?';

    /** Spending keywords → default expense category code (first match wins). */
    private const array EXPENSE_CATEGORIES = [
        'FOOD' => '/\b(?:makan|sarapan|ngopi|kopi|minum|jajan|snack|nasi|bakso|warteg|resto|restoran|kafe|cafe|groceries|sayur|lunch|dinner|breakfast|coffee|food)\b/iu',
        'TRANSPORTATION' => '/\b(?:parkir|bensin|bbm|pertalite|pertamax|tol|ojek|ojol|gojek|grab|taksi|taxi|kereta|krl|mrt|busway|transjakarta|tiket\s+pesawat|parking|fuel)\b/iu',
        'ELECTRICITY' => '/\b(?:listrik|pln|token\s+listrik)\b/iu',
        'INTERNET' => '/\b(?:internet|wifi|indihome|pulsa|kuota|paket\s+data)\b/iu',
        'HEALTHCARE' => '/\b(?:obat|dokter|apotek|klinik|rumah\s+sakit|rs|bpjs|vitamin)\b/iu',
        'EDUCATION' => '/\b(?:spp|sekolah|kursus|buku\s+pelajaran|uang\s+kuliah|ukt)\b/iu',
        'SHOPPING' => '/\b(?:belanja|baju|sepatu|shopee|tokopedia|lazada|marketplace|shopping)\b/iu',
    ];

    /**
     * @return array{intent: TransactionIntent|null, category: string|null, keyword: string|null, warning: string|null}
     */
    public function detect(?string $text): array
    {
        $none = ['intent' => null, 'category' => null, 'keyword' => null, 'warning' => null];

        if ($text === null || trim($text) === '') {
            return $none;
        }

        foreach (self::RULES as [$regex, $meaning, $category]) {
            if (preg_match($regex, $text, $m) === 1) {
                $keyword = mb_strtolower(preg_replace('/\s+/u', ' ', $m[0]) ?? $m[0]);

                if ($meaning === TransactionIntent::EXPENSE && $category === null) {
                    $category = $this->expenseCategory($text);
                }

                return is_string($meaning)
                    ? ['intent' => null, 'category' => null, 'keyword' => $keyword, 'warning' => $meaning]
                    : ['intent' => $meaning, 'category' => $category, 'keyword' => $keyword, 'warning' => null];
            }
        }

        return $none;
    }

    private function expenseCategory(string $text): ?string
    {
        foreach (self::EXPENSE_CATEGORIES as $code => $regex) {
            if (preg_match($regex, $text) === 1) {
                return $code;
            }
        }

        return null;
    }

    /**
     * Extract the other party from text like "dari Pak Budi", "ke PT Maju Jaya", "sama Rina".
     * $incoming = money flowing to the user (prefers "dari"); otherwise prefers "ke/kepada".
     */
    public function counterparty(?string $text, ?bool $incoming = null): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $order = match ($incoming) {
            true => [self::INCOMING_PREPOSITIONS, self::NEUTRAL_PREPOSITIONS],
            false => [self::OUTGOING_PREPOSITIONS, self::NEUTRAL_PREPOSITIONS],
            null => [self::INCOMING_PREPOSITIONS, self::OUTGOING_PREPOSITIONS, self::NEUTRAL_PREPOSITIONS],
        };

        $tokens = preg_split('/\s+/u', trim($text)) ?: [];

        foreach ($order as $prepositions) {
            foreach ($tokens as $i => $token) {
                if (! in_array($this->bare($token), $prepositions, true)) {
                    continue;
                }

                $name = $this->phraseAfter($tokens, $i + 1, self::STOP_WORDS, 4);
                if ($name !== null) {
                    return $this->titleCase($name);
                }
            }
        }

        return null;
    }

    /** "hasil jualan kue dari Bu Rina" → "jualan kue"; "fee proyek website PT ABC" → "fee proyek website". */
    public function activity(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $tokens = preg_split('/\s+/u', trim($text)) ?: [];
        $stops = [...self::STOP_WORDS, ...self::ACTIVITY_EXTRA_STOPS];

        foreach ($tokens as $i => $token) {
            $bare = $this->bare($token);
            foreach ([self::FREELANCE_ACTIVITY, self::BUSINESS_ACTIVITY] as $regex) {
                if ($bare !== '' && preg_match($regex, $bare) === 1) {
                    $rest = $this->phraseAfter($tokens, $i + 1, $stops, 3);
                    $phrase = trim($bare.' '.($rest !== null ? mb_strtolower($rest) : ''));

                    return mb_substr($phrase, 0, 64);
                }
            }
        }

        return null;
    }

    /**
     * What a loan was taken for: "pinjam dari Budi 2jt untuk bayar sekolah" → "bayar sekolah",
     * "ngutang ke bu rina buat keperluan modal usaha" → "modal usaha".
     */
    public function purpose(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $tokens = preg_split('/\s+/u', trim($text)) ?: [];

        foreach ($tokens as $i => $token) {
            if (! in_array($this->bare($token), self::PURPOSE_MARKERS, true)) {
                continue;
            }

            $words = [];
            for ($j = $i + 1, $n = count($tokens); $j < $n && count($words) < 8; $j++) {
                $raw = $tokens[$j];
                $clean = rtrim((string) preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}.]+$/u', '', $raw), '.');
                $bare = mb_strtolower($clean);

                if ($words === [] && in_array($bare, self::PURPOSE_FILLERS, true)) {
                    continue; // "untuk keperluan renovasi" → "renovasi"
                }
                if ($bare === '' || in_array($bare, self::PURPOSE_STOPS, true) || preg_match('/\d/u', $bare) === 1) {
                    break;
                }

                $words[] = $clean;

                if (preg_match('/[,;:)\-]$/u', $raw) === 1) {
                    break;
                }
            }

            if ($words !== []) {
                return mb_substr(implode(' ', $words), 0, 128);
            }
        }

        return null;
    }

    /**
     * Parse an amount: "Rp 1.500.000", "2jt", "1,5 juta", "500rb", "750k", "2500000".
     * Bare numbers need ≥5 digits or thousand separators, so years ("2026") and dates are not amounts.
     */
    public function amount(?string $text, string $currency = 'IDR'): ?Money
    {
        if ($text === null) {
            return null;
        }

        $pattern = '/(?<![\p{L}\d.,])(rp\.?\s*)?(\d+(?:[.,]\d+)*)\s*(jt|juta|rb|ribu|k|miliar|milyar|m)?(?![\p{L}\d])/iu';

        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) === 0) {
            return null;
        }

        foreach ($matches as $m) {
            $hasPrefix = trim($m[1] ?? '') !== '';
            $number = $m[2];
            $unit = mb_strtolower($m[3] ?? '');
            $multiplier = match ($unit) {
                'jt', 'juta' => '1000000',
                'rb', 'ribu', 'k' => '1000',
                'miliar', 'milyar', 'm' => '1000000000',
                default => '1',
            };

            if ($unit !== '') {
                // With a unit, a single separator is a decimal mark ("1,5jt", "1.5 juta").
                $decimal = preg_match('/^\d+[.,]\d+$/', $number) === 1
                    ? str_replace(',', '.', $number)
                    : str_replace(['.', ','], '', $number);
            } elseif (preg_match('/^\d{1,3}(?:[.,]\d{3})+$/', $number) === 1) {
                $decimal = str_replace(['.', ','], '', $number);              // 1.500.000
            } elseif (preg_match('/^(\d{1,3}(?:\.\d{3})*),(\d{1,2})$/', $number, $id) === 1) {
                $decimal = str_replace('.', '', $id[1]).'.'.$id[2];           // 1.500.000,50
            } elseif (preg_match('/^\d+$/', $number) === 1 && ($hasPrefix || strlen($number) >= 5)) {
                $decimal = $number;
            } else {
                continue;
            }

            if (preg_match('/^\d+(\.\d+)?$/', $decimal) !== 1) {
                continue;
            }

            $value = bcmul($decimal, $multiplier, 2);
            if (bccomp($value, '0', 2) <= 0 || strlen(explode('.', $value)[0]) > 18) {
                continue;
            }

            return Money::of($value, $currency);
        }

        return null;
    }

    /**
     * @param  list<string>  $tokens
     * @param  list<string>  $stops
     */
    private function phraseAfter(array $tokens, int $start, array $stops, int $max): ?string
    {
        $words = [];

        for ($j = $start, $n = count($tokens); $j < $n && count($words) < $max; $j++) {
            $raw = $tokens[$j];
            $clean = (string) preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}.]+$/u', '', $raw);
            $clean = rtrim($clean, '.');
            $bare = mb_strtolower($clean);

            if ($bare === '' || in_array($bare, $stops, true) || preg_match('/\d/u', $bare) === 1) {
                break;
            }

            $words[] = $clean;

            // A comma/semicolon/dash after the word closes the phrase.
            if (preg_match('/[,;:)\-]$/u', $raw) === 1) {
                break;
            }
        }

        return $words === [] ? null : implode(' ', $words);
    }

    private function bare(string $token): string
    {
        return mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $token));
    }

    private function titleCase(string $name): string
    {
        if ($name !== mb_strtolower($name)) {
            return $name; // user already chose a casing
        }

        return implode(' ', array_map(
            static fn (string $w): string => in_array($w, self::UPPERCASE_TOKENS, true) ? mb_strtoupper($w) : mb_convert_case($w, MB_CASE_TITLE),
            explode(' ', $name),
        ));
    }
}
