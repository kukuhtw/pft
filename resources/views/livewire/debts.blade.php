@php
    $kindLabel = [
        'LOAN' => ['Pinjam', 'bg-amber-100 text-amber-800'],
        'OPENING' => ['Hutang awal', 'bg-amber-100 text-amber-800'],
        'INCREASE' => ['Tambah hutang', 'bg-amber-100 text-amber-800'],
        'PAYMENT' => ['Bayar', 'bg-emerald-100 text-emerald-800'],
        'DECREASE' => ['Kurangi hutang', 'bg-emerald-100 text-emerald-800'],
    ];
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Catatan Hutang</h1>
            <p class="text-sm text-slate-500">Kapan berhutang, kepada siapa, untuk keperluan apa, dan berapa sisanya. Sisa hutang dihitung dari pembukuan.</p>
        </div>
        <div class="flex gap-1 text-sm" role="tablist">
            @foreach (['aktif' => 'Belum lunas ('.$openCount.')', 'semua' => 'Semua ('.($openCount + $settledCount).')'] as $value => $label)
                <button type="button" wire:click="$set('show', '{{ $value }}')" role="tab" aria-selected="{{ $show === $value ? 'true' : 'false' }}"
                        @class(['rounded-md px-3 py-1.5', 'bg-slate-900 text-white' => $show === $value, 'border border-slate-300 bg-white hover:bg-slate-100' => $show !== $value])>{{ $label }}</button>
            @endforeach
        </div>
    </div>

    <section class="rounded-lg border border-slate-200 bg-white p-4">
        <p class="text-xs uppercase tracking-wide text-slate-500">Total sisa hutang</p>
        <p class="mt-1 text-2xl font-semibold tabular-nums text-amber-700">{{ $totalRemaining->format() }}</p>
    </section>

    <livewire:quick-entry />

    @forelse ($rows as $row)
        <section wire:key="debt-{{ $row['account']->id }}" class="rounded-lg border border-slate-200 bg-white">
            <header class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 p-4">
                <div class="min-w-0">
                    <h2 class="font-semibold">
                        Hutang kepada
                        @if ($row['counterparty_id'])
                            <a href="{{ route('transactions', ['counterpartyId' => $row['counterparty_id']]) }}" wire:navigate class="hover:underline">{{ $row['party'] }}</a>
                        @else
                            {{ $row['party'] }}
                        @endif
                        @if ($row['settled'])
                            <span class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 text-xs font-medium text-emerald-800">Lunas</span>
                        @endif
                    </h2>
                    <p class="text-xs text-slate-500">
                        {{ $row['account']->name }}
                        @if ($row['since']) · sejak {{ $row['since']->translatedFormat('d M Y') }} @endif
                    </p>
                    @if ($row['purposes'] !== [])
                        <p class="mt-1 text-sm text-slate-700">Keperluan: {{ implode(', ', $row['purposes']) }}</p>
                    @endif
                </div>
                <dl class="grid grid-cols-3 gap-4 text-right text-sm">
                    <div><dt class="text-xs text-slate-500">Dipinjam</dt><dd class="tabular-nums">{{ $row['borrowed']->format() }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Dibayar</dt><dd class="tabular-nums">{{ $row['repaid']->format() }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Sisa</dt><dd class="font-semibold tabular-nums text-amber-700">{{ $row['remaining']->format() }}</dd></div>
                </dl>
            </header>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-slate-500">
                        <tr>
                            <th class="px-4 py-2 font-medium">Tanggal</th>
                            <th class="px-4 py-2 font-medium">Jenis</th>
                            <th class="px-4 py-2 font-medium">Keperluan / catatan</th>
                            <th class="px-4 py-2 text-right font-medium">Jumlah</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($row['entries'] as $entry)
                            <tr wire:key="entry-{{ $entry['id'] }}">
                                <td class="whitespace-nowrap px-4 py-2 tabular-nums">{{ $entry['date']->translatedFormat('d M Y') }}</td>
                                <td class="whitespace-nowrap px-4 py-2">
                                    <span class="rounded px-1.5 py-0.5 text-xs font-medium {{ $kindLabel[$entry['kind']][1] }}">{{ $kindLabel[$entry['kind']][0] }}</span>
                                </td>
                                <td class="px-4 py-2">
                                    @if ($entry['purpose'])
                                        <span class="font-medium">{{ $entry['purpose'] }}</span>
                                    @elseif ($entry['kind'] === 'LOAN')
                                        <span class="text-slate-400">keperluan belum dicatat</span>
                                    @endif
                                    @if ($entry['description'] && $entry['description'] !== $entry['purpose'])
                                        <span class="block text-xs text-slate-500">{{ $entry['description'] }}</span>
                                    @endif
                                    @if ($entry['account'])
                                        <span class="block text-xs text-slate-400">{{ $entry['increases'] ? 'masuk ke' : 'dibayar dari' }} {{ $entry['account'] }}</span>
                                    @endif
                                </td>
                                <td @class(['whitespace-nowrap px-4 py-2 text-right tabular-nums', 'text-amber-700' => $entry['increases'], 'text-emerald-700' => ! $entry['increases']])>
                                    {{ $entry['increases'] ? '+' : '−' }}{{ $entry['amount']->format() }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-3 text-slate-500">Belum ada catatan.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @empty
        <section class="rounded-lg border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-500">
            @if ($show === 'aktif' && $settledCount > 0)
                Semua hutang sudah lunas. 🎉
            @else
                Belum ada catatan hutang. Catat lewat kotak di atas, misalnya <em>"pinjam dari Pak Budi 2jt untuk bayar sekolah"</em>.
            @endif
        </section>
    @endforelse
</div>
