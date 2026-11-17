@php
    /** Compact axis/label format: 12,5jt · 750rb · 1,2M */
    $compact = static function (float $v): string {
        $abs = abs($v);
        [$div, $unit] = match (true) { $abs >= 1e9 => [1e9, 'M'], $abs >= 1e6 => [1e6, 'jt'], $abs >= 1e3 => [1e3, 'rb'], default => [1, ''] };
        $n = $v / $div;
        $text = number_format($n, abs($n) < 10 && $div > 1 ? 1 : 0, ',', '.');
        // Trim zeros only from a decimal part ("2,0" → "2"), never from integers ("20" stays "20").
        return (str_contains($text, ',') ? rtrim(rtrim($text, '0'), ',') : $text).$unit;
    };
    $chartHeight = 180;
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Laporan</h1>
            <p class="text-sm text-slate-500">{{ $fromDate->translatedFormat('d M Y') }} – {{ $toDate->translatedFormat('d M Y') }}</p>
        </div>
        <a href="{{ route('export.transactions', ['from' => $fromDate->toDateString(), 'to' => $toDate->toDateString()]) }}"
           class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm hover:bg-slate-100">⬇ Ekspor CSV</a>
    </div>

    {{-- Filters: one row above the charts --}}
    <div class="flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-4">
        <div class="flex flex-wrap rounded-md border border-slate-300 p-0.5 text-sm">
            @foreach (['this_month' => 'Bulan ini', 'last_month' => 'Bulan lalu', 'last_3_months' => '3 bulan', 'this_year' => 'Tahun ini'] as $key => $label)
                <button type="button" wire:click="preset('{{ $key }}')" class="rounded px-3 py-1 text-slate-600 hover:bg-slate-100">{{ $label }}</button>
            @endforeach
        </div>
        <label class="text-sm"><span class="mb-1 block font-medium">Dari</span>
            <input wire:model.live="from" type="date" class="rounded-md border border-slate-300 px-3 py-1.5"></label>
        <label class="text-sm"><span class="mb-1 block font-medium">Sampai</span>
            <input wire:model.live="to" type="date" class="rounded-md border border-slate-300 px-3 py-1.5"></label>
    </div>

    <section class="grid grid-cols-2 gap-3 md:grid-cols-4">
        @foreach ([['Pemasukan', $summary->totalIncome], ['Pengeluaran', $summary->totalExpenses], ['Selisih', $summary->netIncome], ['Arus kas bersih', $summary->netCashFlow]] as [$label, $money])
            <div class="rounded-lg border border-slate-200 bg-white p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500">{{ $label }}</p>
                <p class="mt-1 text-lg font-semibold tabular-nums">{{ $money->format() }}</p>
            </div>
        @endforeach
    </section>

    {{-- 12-month trend: grouped columns, 2 series, legend + tooltip + table view --}}
    <section class="rounded-lg border border-slate-200 bg-white p-5" aria-labelledby="trend-title">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <h2 id="trend-title" class="font-semibold">Pemasukan vs pengeluaran, 12 bulan</h2>
            <ul class="flex gap-4 text-sm text-slate-600" aria-label="Legenda">
                <li class="flex items-center gap-1.5"><span class="inline-block size-2.5 rounded-sm bg-series-income"></span>Pemasukan</li>
                <li class="flex items-center gap-1.5"><span class="inline-block size-2.5 rounded-sm bg-series-expense"></span>Pengeluaran</li>
            </ul>
        </div>

        <div class="flex gap-2" role="img" aria-label="Grafik batang pemasukan dan pengeluaran per bulan. Angka lengkap ada di tabel di bawahnya.">
            {{-- Y axis --}}
            <div class="relative w-10 shrink-0 text-right text-[11px] tabular-nums text-viz-muted" style="height: {{ $chartHeight }}px">
                @foreach ([1, 0.5, 0] as $f)
                    <span class="absolute right-0 -translate-y-1/2" style="top: {{ (1 - $f) * $chartHeight }}px">{{ $compact($peak * $f) }}</span>
                @endforeach
            </div>
            <div class="min-w-0 flex-1 overflow-x-auto">
                <div class="relative min-w-[480px]" style="height: {{ $chartHeight }}px">
                    @foreach ([0, 0.5] as $f)
                        <div class="absolute inset-x-0 border-t border-viz-grid" style="top: {{ $f * $chartHeight }}px"></div>
                    @endforeach
                    <div class="absolute inset-x-0 bottom-0 border-t border-viz-axis"></div>
                    <div class="absolute inset-0 flex items-end justify-around">
                        @foreach ($trend as $m)
                            @php
                                $inc = max(0.0, (float) $m['income']->amount);
                                $exp = max(0.0, (float) $m['expense']->amount);
                            @endphp
                            <div class="group relative flex h-full items-end gap-0.5 px-1" tabindex="0" aria-label="{{ $m['month']->translatedFormat('F Y') }}: pemasukan {{ $m['income']->format() }}, pengeluaran {{ $m['expense']->format() }}">
                                <div class="w-3 rounded-t bg-series-income" style="height: {{ round($inc / $peak * $chartHeight) }}px"></div>
                                <div class="w-3 rounded-t bg-series-expense" style="height: {{ round($exp / $peak * $chartHeight) }}px"></div>
                                <div class="pointer-events-none absolute bottom-full left-1/2 z-10 mb-1 hidden -translate-x-1/2 whitespace-nowrap rounded-md border border-slate-200 bg-white px-2.5 py-1.5 text-xs shadow-md group-hover:block group-focus:block">
                                    <p class="font-medium text-slate-900">{{ $m['month']->translatedFormat('F Y') }}</p>
                                    <p class="flex items-center gap-1.5 text-slate-600"><span class="inline-block size-2 rounded-sm bg-series-income"></span>Pemasukan <span class="ml-auto pl-3 tabular-nums text-slate-900">{{ $m['income']->format() }}</span></p>
                                    <p class="flex items-center gap-1.5 text-slate-600"><span class="inline-block size-2 rounded-sm bg-series-expense"></span>Pengeluaran <span class="ml-auto pl-3 tabular-nums text-slate-900">{{ $m['expense']->format() }}</span></p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="flex min-w-[480px] justify-around pt-1 text-[11px] text-viz-muted">
                    @foreach ($trend as $m)
                        <span>{{ $m['month']->translatedFormat('M') }}</span>
                    @endforeach
                </div>
            </div>
        </div>

        <details class="mt-4 text-sm">
            <summary class="cursor-pointer text-slate-500">Lihat tabel</summary>
            <table class="mt-2 w-full">
                <thead class="text-left text-xs uppercase tracking-wide text-slate-500"><tr><th class="py-1">Bulan</th><th class="text-right">Pemasukan</th><th class="text-right">Pengeluaran</th><th class="text-right">Selisih</th></tr></thead>
                <tbody class="divide-y divide-slate-100 tabular-nums">
                    @foreach ($trend as $m)
                        <tr><td class="py-1">{{ $m['month']->translatedFormat('F Y') }}</td><td class="text-right">{{ $m['income']->format() }}</td><td class="text-right">{{ $m['expense']->format() }}</td><td class="text-right">{{ $m['net']->format() }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </details>
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        @foreach ([['Pengeluaran per kategori', $expense, 'bg-series-expense', $summary->totalExpenses], ['Pemasukan per kategori', $income, 'bg-series-income', $summary->totalIncome]] as [$title, $rows, $color, $total])
            <section class="rounded-lg border border-slate-200 bg-white p-5">
                <h2 class="mb-3 font-semibold">{{ $title }}</h2>
                @php $max = max([1.0, ...array_map(static fn ($r) => (float) $r['total']->amount, $rows)]); @endphp
                <ul class="space-y-2.5 text-sm">
                    @forelse ($rows as $row)
                        @php $share = $total->isPositive() ? round((float) $row['total']->amount / (float) $total->amount * 100) : 0; @endphp
                        <li>
                            <div class="mb-1 flex justify-between gap-2">
                                <a href="{{ route('transactions', ['categoryId' => $row['category']->id, 'dateFrom' => $fromDate->toDateString(), 'dateTo' => $toDate->toDateString()]) }}" wire:navigate class="truncate hover:underline">{{ $row['category']->name }}</a>
                                <span class="shrink-0 tabular-nums"><span class="font-medium">{{ $row['total']->format() }}</span> <span class="text-slate-400">{{ $share }}%</span></span>
                            </div>
                            <div class="h-2 rounded-r bg-transparent">
                                <div class="h-2 rounded-r {{ $color }}" style="width: {{ max(1, round(max(0, (float) $row['total']->amount) / $max * 100)) }}%"></div>
                            </div>
                        </li>
                    @empty
                        <li class="text-slate-500">Belum ada data di periode ini.</li>
                    @endforelse
                </ul>
            </section>
        @endforeach
    </div>

    <section class="rounded-lg border border-slate-200 bg-white p-5">
        <h2 class="mb-1 font-semibold">Uang masuk menurut sumber</h2>
        <p class="mb-3 text-sm text-slate-500">Pinjaman diterima menambah kas, tapi tidak dihitung sebagai pendapatan.</p>
        <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($inflows as $intent => $money)
                <div class="rounded-md border border-slate-100 p-3">
                    <dt class="text-xs text-slate-500">{{ $intentEnum::from($intent)->label() }}</dt>
                    <dd class="mt-0.5 font-semibold tabular-nums">{{ $money->format() }}</dd>
                </div>
            @endforeach
        </dl>
    </section>
</div>
