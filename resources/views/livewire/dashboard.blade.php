<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Dashboard</h1>
            <p class="text-sm text-slate-500">{{ $from->translatedFormat('d M Y') }} – {{ $to->translatedFormat('d M Y') }}</p>
        </div>
        <div class="flex flex-wrap rounded-md border border-slate-300 bg-white p-0.5 text-sm">
            @foreach (['this_month' => 'Bulan ini', 'last_month' => 'Bulan lalu', 'this_year' => 'Tahun ini'] as $key => $label)
                <button wire:click="$set('period', '{{ $key }}')"
                        @class(['rounded px-3 py-1', 'bg-slate-900 text-white' => $period === $key, 'text-slate-600 hover:bg-slate-100' => $period !== $key])>{{ $label }}</button>
            @endforeach
        </div>
    </div>

    {{-- Summary --}}
    <section class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6">
        @foreach ([
            ['Pemasukan', $summary->totalIncome, 'text-emerald-700'],
            ['Pengeluaran', $summary->totalExpenses, 'text-rose-700'],
            ['Selisih', $summary->netIncome, $summary->netIncome->isNegative() ? 'text-rose-700' : 'text-emerald-700'],
            ['Total aset', $summary->totalAssets, 'text-slate-900'],
            ['Total hutang', $summary->totalLiabilities, 'text-amber-700'],
            ['Kekayaan bersih', $summary->netWorth, $summary->netWorth->isNegative() ? 'text-rose-700' : 'text-slate-900'],
        ] as [$label, $money, $color])
            <div class="rounded-lg border border-slate-200 bg-white p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500">{{ $label }}</p>
                <p class="mt-1 text-lg font-semibold tabular-nums {{ $color }}">{{ $money->format() }}</p>
            </div>
        @endforeach
    </section>

    @if ($budgetAlerts !== [])
        <section class="rounded-lg border border-slate-200 bg-white p-5" aria-labelledby="budget-alerts">
            <div class="mb-2 flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                <h2 id="budget-alerts" class="font-semibold">Budget bulan ini perlu perhatian</h2>
                <a href="{{ route('budgets') }}" wire:navigate class="text-sm text-slate-500 hover:underline">Lihat budget</a>
            </div>
            <ul class="grid gap-3 sm:grid-cols-2">
                @foreach ($budgetAlerts as $row)
                    <li class="text-sm">
                        <div class="mb-1 flex justify-between gap-2">
                            <span class="font-medium">{{ $row['category']->name }}</span>
                            <span class="tabular-nums text-slate-600">{{ $row['actual']->format() }} / {{ $row['budget']->format() }}</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded bg-slate-100">
                            <div @class(['h-2 rounded-r', 'bg-status-critical' => $row['state'] === 'over', 'bg-status-warning' => $row['state'] === 'warning']) style="width: {{ min(100, $row['percent']) }}%"></div>
                        </div>
                        <p class="mt-0.5 text-xs text-slate-600">{{ $row['state'] === 'over' ? '✕ Melebihi budget' : '⚠ Hampir habis' }} · {{ $row['percent'] }}%</p>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <livewire:quick-entry />

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- Money in, by meaning --}}
        <section class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="mb-3 font-semibold">Uang masuk menurut sumber</h2>
            <dl class="divide-y divide-slate-100 text-sm">
                @foreach ($inflows as $intent => $money)
                    <div class="flex items-center justify-between py-2">
                        <dt>
                            {{ $intentEnum::from($intent)->label() }}
                            @if ($intent === 'LOAN_RECEIVED')
                                <span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-800">bukan pendapatan</span>
                            @endif
                        </dt>
                        <dd class="font-medium tabular-nums">
                            <a href="{{ route('transactions', ['intent' => $intent, 'dateFrom' => $from->toDateString(), 'dateTo' => $to->toDateString()]) }}" wire:navigate class="hover:underline">{{ $money->format() }}</a>
                        </dd>
                    </div>
                @endforeach
            </dl>
        </section>

        {{-- Debts per party --}}
        <section class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="mb-3 font-semibold">Hutang per pihak</h2>
            @forelse ($debts as $debt)
                <div class="border-b border-slate-100 py-2 last:border-0">
                    <div class="flex items-center justify-between text-sm">
                        <span class="font-medium">
                            @if ($debt['counterparty_id'])
                                <a href="{{ route('transactions', ['counterpartyId' => $debt['counterparty_id']]) }}" wire:navigate class="hover:underline">{{ $debt['party'] }}</a>
                            @else
                                {{ $debt['party'] }}
                            @endif
                        </span>
                        <span class="font-semibold tabular-nums text-amber-700">{{ $debt['total']->format() }}</span>
                    </div>
                    @if (count($debt['accounts']) > 1)
                        <ul class="mt-1 space-y-0.5 pl-3 text-xs text-slate-500">
                            @foreach ($debt['accounts'] as $account)
                                <li class="flex justify-between"><span>{{ $account['name'] }}</span><span class="tabular-nums">{{ $account['balance']->format() }}</span></li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @empty
                <p class="text-sm text-slate-500">Tidak ada hutang aktif. 🎉</p>
            @endforelse
        </section>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="rounded-lg border border-slate-200 bg-white p-5">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="font-semibold">Akun</h2>
                <a href="{{ route('accounts') }}" wire:navigate class="text-sm text-slate-500 hover:underline">Kelola</a>
            </div>
            <ul class="divide-y divide-slate-100 text-sm">
                @forelse ($accounts as $account)
                    <li class="flex justify-between py-2">
                        <span>{{ $account->name }} <span class="text-xs text-slate-400">{{ $account->account_type_code }}</span></span>
                        <span @class(['tabular-nums font-medium', 'text-amber-700' => $account->isLiability()])>{{ $account->balance()->format() }}</span>
                    </li>
                @empty
                    <li class="py-2 text-slate-500">Belum ada akun. <a href="{{ route('accounts') }}" wire:navigate class="underline">Buat akun pertama</a>.</li>
                @endforelse
            </ul>
        </section>

        <section class="rounded-lg border border-slate-200 bg-white p-5 lg:col-span-2">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="font-semibold">Transaksi terakhir</h2>
                <a href="{{ route('transactions') }}" wire:navigate class="text-sm text-slate-500 hover:underline">Lihat semua</a>
            </div>
            <ul class="divide-y divide-slate-100">
                @forelse ($recent as $t)
                    <li wire:key="recent-{{ $t->id }}">@include('livewire.partials.transaction-row', ['t' => $t])</li>
                @empty
                    <li class="py-2 text-sm text-slate-500">Belum ada transaksi.</li>
                @endforelse
            </ul>
        </section>
    </div>
</div>
