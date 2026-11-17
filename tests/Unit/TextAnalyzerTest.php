<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Interpretation\Enums\TransactionIntent;
use App\Modules\Interpretation\Services\TextAnalyzer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TextAnalyzerTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: TransactionIntent|null, 2: string|null, 3: string|null, 4: string|null, 5: string|null}>
     */
    public static function sentences(): iterable
    {
        // text, intent, category, counterparty, activity, amount
        yield 'loan from person' => ['pinjam dari Pak Budi 2jt', TransactionIntent::LOAN_RECEIVED, null, 'Pak Budi', null, '2000000.00'];
        yield 'loan from cooperative' => ['dapat pinjaman dari koperasi kantor sebesar 10 juta', TransactionIntent::LOAN_RECEIVED, null, 'Koperasi Kantor', null, '10000000.00'];
        yield 'loan with "sama"' => ['pinjam sama mas joko, untuk modal', TransactionIntent::LOAN_RECEIVED, null, 'Mas Joko', null, null];
        yield 'salary from employer' => ['gaji November dari PT Maju Jaya', TransactionIntent::SALARY_INCOME, 'SALARY', 'PT Maju Jaya', null, null];
        yield 'thr is salary, year is not an amount' => ['thr 2026', TransactionIntent::SALARY_INCOME, 'SALARY', null, null, null];
        yield 'selling activity' => ['hasil jualan kue dari Bu Rina 500rb', TransactionIntent::ACTIVITY_INCOME, 'BUSINESS', 'Bu Rina', 'jualan kue', '500000.00'];
        yield 'freelance activity' => ['fee proyek website PT ABC 7.500.000', TransactionIntent::ACTIVITY_INCOME, 'FREELANCE', null, 'fee proyek website', '7500000.00'];
        yield 'teaching activity' => ['ngajar les privat 350rb', TransactionIntent::ACTIVITY_INCOME, 'FREELANCE', null, 'ngajar les privat', '350000.00'];
        yield 'debt repayment to person' => ['bayar hutang ke Budi 1,5 juta', TransactionIntent::DEBT_REPAYMENT, null, 'Budi', null, '1500000.00'];
        yield 'installment to bank' => ['bayar cicilan KTA ke Bank Jago Rp 1.250.000', TransactionIntent::DEBT_REPAYMENT, null, 'Bank Jago', null, '1250000.00'];
        yield 'give money back' => ['balikin uang ke Rina 200k', TransactionIntent::DEBT_REPAYMENT, null, 'Rina', null, '200000.00'];
        yield 'paying salary is an expense' => ['bayar gaji karyawan', TransactionIntent::EXPENSE, null, null, null, null];
        yield 'cash withdrawal' => ['tarik tunai 500rb', TransactionIntent::INTERNAL_TRANSFER, null, null, null, '500000.00'];
        yield 'interest' => ['bunga deposito', TransactionIntent::OTHER_INCOME, 'INTEREST_INCOME', null, null, null];
    }

    #[DataProvider('sentences')]
    public function test_understands_indonesian_entries(string $text, ?TransactionIntent $intent, ?string $category, ?string $counterparty, ?string $activity, ?string $amount): void
    {
        $a = new TextAnalyzer;
        $detected = $a->detect($text);
        $incoming = $intent === null ? null : ($intent->isIncome() || $intent === TransactionIntent::LOAN_RECEIVED);

        $this->assertSame($intent, $detected['intent']);
        $this->assertSame($category, $detected['category']);
        $this->assertSame($counterparty, $a->counterparty($text, $incoming));
        if ($intent === TransactionIntent::ACTIVITY_INCOME) {
            $this->assertSame($activity, $a->activity($text));
        }
        $this->assertSame($amount, $a->amount($text)?->amount);
    }

    public function test_lending_out_is_flagged_not_misread_as_a_loan_received(): void
    {
        $detected = (new TextAnalyzer)->detect('minjemin Andi 300rb');

        $this->assertNull($detected['intent']);
        $this->assertSame(TextAnalyzer::LENDING_OUT, $detected['warning']);
    }

    public function test_amount_formats(): void
    {
        $a = new TextAnalyzer;

        $this->assertSame('1500000.50', $a->amount('Rp1.500.000,50')?->amount);
        $this->assertSame('750000.00', $a->amount('750k')?->amount);
        $this->assertSame('2500000.00', $a->amount('2500000')?->amount);
        $this->assertNull($a->amount('tanggal 17 bulan 11'));
    }
}
