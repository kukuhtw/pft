<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Livewire\Transactions;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Debts\Services\DebtService;
use App\Modules\Shared\Domain\Money;
use App\Modules\Transactions\Data\TransactionInput;
use App\Modules\Transactions\Enums\TransactionStatus;
use App\Modules\Transactions\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuildsFinance;
use Tests\TestCase;

final class TransactionEditTest extends TestCase
{
    use BuildsFinance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_edit_expense_amount_description_category_and_source_account(): void
    {
        $user = $this->provisionedUser();
        $bca = $this->openAccount($user, 'BCA', opening: 1000000);
        $gopay = $this->openAccount($user, 'GoPay', type: 'EWALLET', opening: 500000);
        $original = $this->expense($user, $bca, 45000);
        $this->actingAs($user);

        Livewire::test(Transactions::class)
            ->call('edit', $original->id)
            ->assertSet('editAmount', '45000')
            ->set('editAmount', '60rb')
            ->set('editDescription', 'makan malam')
            ->set('editCategoryId', $this->category($user, 'TRANSPORTATION')->id)
            ->set('editSourceId', $gopay->id)
            ->call('saveEdit')
            ->assertHasNoErrors()
            ->assertSee('diedit')
            ->assertSee('diubah (versi lama)');

        $this->assertSame('1000000.00', $this->balanceOf($bca), 'Old version fully reversed.');
        $this->assertSame('440000.00', $this->balanceOf($gopay));

        $original->refresh();
        $this->assertSame(TransactionStatus::REVERSED, $original->status);
        $this->assertSame('Diubah', $original->reversal_reason);
        $new = Transaction::query()->where('user_id', $user->id)->where('status', 'POSTED')->where('type', 'EXPENSE')->sole();
        $this->assertSame($original->id, $new->metadata['amends']);
        $this->assertSame('makan malam', $new->description);
        $this->assertSame('TRANSPORTATION', $new->category?->code);
        $this->assertSame('60000.00', $this->summary($user)->totalExpenses->amount);

        $log = AuditLog::query()->where('event', 'transaction.amended')->sole();
        $this->assertEqualsCanonicalizing(['amount', 'description', 'category_id', 'source_account_id'], $log->properties['changed']);
        $this->assertLedgerIntact($user);
    }

    public function test_edit_is_atomic_when_the_new_version_is_invalid(): void
    {
        $user = $this->provisionedUser();
        $bca = $this->openAccount($user, 'BCA', opening: 100000);
        $original = $this->expense($user, $bca, 50000);
        $this->actingAs($user);

        Livewire::test(Transactions::class)->call('edit', $original->id)
            ->set('editAmount', '5jt')->call('saveEdit')->assertHasErrors('saveEdit'); // overdraft

        $this->assertSame(TransactionStatus::POSTED, $original->fresh()->status, 'Nothing changed.');
        $this->assertSame('50000.00', $this->balanceOf($bca));
        $this->assertSame(1, Transaction::query()->where('user_id', $user->id)->where('type', 'EXPENSE')->count());
    }

    public function test_edit_income_destination_and_transfer(): void
    {
        $user = $this->provisionedUser();
        $bca = $this->openAccount($user, 'BCA', opening: 1000000);
        $bni = $this->openAccount($user, 'BNI', opening: 0);
        $income = $this->income($user, $bca, 10000000);
        $transfer = $this->transfer($user, $bca, $bni, 200000);
        $this->actingAs($user);

        Livewire::test(Transactions::class)->call('edit', $income->id)
            ->set('editDestinationId', $bni->id)->set('editAmount', '9500000')->call('saveEdit')->assertHasNoErrors();
        Livewire::test(Transactions::class)->call('edit', $transfer->id)
            ->set('editAmount', '300rb')->call('saveEdit')->assertHasNoErrors();

        $this->assertSame('700000.00', $this->balanceOf($bca));
        $this->assertSame('9800000.00', $this->balanceOf($bni));
        $this->assertSame('9500000.00', $this->summary($user)->totalIncome->amount);
        $this->assertSame('0.00', $this->summary($user)->totalExpenses->amount);
        $this->assertLedgerIntact($user);
    }

    public function test_receivable_and_foreign_accounts_are_rejected_and_loans_are_not_editable(): void
    {
        $user = $this->provisionedUser();
        $bca = $this->openAccount($user, 'BCA', opening: 1000000);
        $receivable = $this->openAccount($user, 'Piutang Andi', type: 'RECEIVABLE');
        $this->transfer($user, $bca, $receivable, 100000);
        $expense = $this->expense($user, $bca, 10000);
        $stranger = $this->provisionedUser();
        $foreign = $this->openAccount($stranger, 'Foreign', opening: 1000000);
        $this->actingAs($user);

        Livewire::test(Transactions::class)->call('edit', $expense->id)
            ->set('editSourceId', $receivable->id)->call('saveEdit')->assertHasErrors('saveEdit');
        Livewire::test(Transactions::class)->call('edit', $expense->id)
            ->set('editSourceId', $foreign->id)->call('saveEdit')->assertHasErrors('saveEdit');
        $this->assertSame(TransactionStatus::POSTED, $expense->fresh()->status);

        $loan = app(DebtService::class)->loanReceived($user, new TransactionInput(
            amount: Money::of(500000), destinationAccountId: $bca->id, counterpartyName: 'Pak Budi',
        ));
        Livewire::test(Transactions::class)->call('edit', $loan->id)->assertHasErrors('reverse')->assertSet('editingId', null);

        // Another user's transaction cannot even be opened.
        $this->actingAs($stranger);
        Livewire::test(Transactions::class)->call('edit', $expense->id)->assertStatus(404);
    }
}
