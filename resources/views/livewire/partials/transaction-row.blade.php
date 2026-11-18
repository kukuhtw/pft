@php
    /** @var \App\Modules\Transactions\Models\Transaction $t */
    $money = \App\Modules\Shared\Domain\Money::fromDatabase($t->getRawOriginal('amount'), $t->currency_code);
    $intent = $t->intent?->value;
    $inflow = in_array($intent, ['SALARY_INCOME', 'ACTIVITY_INCOME', 'OTHER_INCOME', 'LOAN_RECEIVED'], true);
    $outflow = in_array($intent, ['EXPENSE', 'DEBT_REPAYMENT'], true);
    $badge = match ($intent) {
        'SALARY_INCOME', 'ACTIVITY_INCOME', 'OTHER_INCOME' => 'bg-emerald-100 text-emerald-800',
        'LOAN_RECEIVED', 'DEBT_REPAYMENT' => 'bg-amber-100 text-amber-800',
        'EXPENSE' => 'bg-rose-100 text-rose-800',
        default => 'bg-slate-100 text-slate-700',
    };
    $warnings = $t->metadata['interpretation']['warnings'] ?? [];
    $reversed = $t->status === \App\Modules\Transactions\Enums\TransactionStatus::REVERSED;
@endphp
<div @class(['flex items-start justify-between gap-3 py-2.5', 'opacity-50' => $reversed])>
    <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-1.5 text-sm">
            <span class="rounded px-1.5 py-0.5 text-xs font-medium {{ $badge }}">{{ $t->intent?->label() ?? $t->type->value }}</span>
            @if ($t->counterparty)
                <span class="font-medium">{{ $t->counterparty->name }}</span>
            @endif
            @if ($t->activity)
                <span class="text-slate-500">· {{ $t->activity }}</span>
            @endif
            @if ($t->purpose)
                <span class="text-slate-500">· untuk {{ $t->purpose }}</span>
            @endif
            @if ($reversed)
                <span class="rounded bg-slate-200 px-1.5 py-0.5 text-xs">{{ $t->reversal_reason === \App\Modules\Transactions\Services\TransactionAmendmentService::REVERSAL_REASON ? 'diubah (versi lama)' : 'dibatalkan' }}</span>
            @elseif (isset($t->metadata['amends']))
                <span class="rounded bg-sky-100 px-1.5 py-0.5 text-xs text-sky-800">diedit</span>
            @endif
            @foreach ($warnings as $warning)
                <span class="rounded bg-yellow-100 px-1.5 py-0.5 text-xs text-yellow-800" title="Teks deskripsi tidak sesuai jenis transaksi">⚠ {{ $warning }}</span>
            @endforeach
        </div>
        <p class="truncate text-xs text-slate-500">
            {{ $t->transaction_date->translatedFormat('d M Y') }}
            · {{ implode(' → ', array_filter([$t->sourceAccount?->name ?? $t->category?->name, $t->destinationAccount?->name ?? $t->category?->name])) }}
            @if ($t->description) · {{ $t->description }} @endif
        </p>
    </div>
    <span @class(['shrink-0 text-sm font-semibold tabular-nums', 'text-emerald-700' => $inflow, 'text-rose-700' => $outflow])>
        {{ $inflow ? '+' : ($outflow ? '−' : '') }}{{ $money->format() }}
    </span>
</div>
