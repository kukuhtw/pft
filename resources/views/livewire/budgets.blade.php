@php
    $stateStyle = [
        'ok' => ['bg-status-good', '✓', 'Aman'],
        'warning' => ['bg-status-warning', '⚠', 'Hampir habis'],
        'over' => ['bg-status-critical', '✕', 'Melebihi budget'],
    ];
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Budget</h1>
            <p class="text-sm text-slate-500">Batas pengeluaran per kategori. Realisasi dihitung dari pembukuan; transfer, pinjaman, dan pembayaran hutang tidak ikut terhitung.</p>
        </div>
        <div class="flex items-center gap-1 text-sm">
            <button wire:click="shiftMonth(-1)" class="rounded-md border border-slate-300 bg-white px-2.5 py-1.5 hover:bg-slate-100" aria-label="Bulan sebelumnya">‹</button>
            <span class="min-w-32 text-center font-medium">{{ $month->translatedFormat('F Y') }}</span>
            <button wire:click="shiftMonth(1)" class="rounded-md border border-slate-300 bg-white px-2.5 py-1.5 hover:bg-slate-100" aria-label="Bulan berikutnya">›</button>
        </div>
    </div>

    @if ($flash)
        <div class="rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ $flash }}</div>
    @endif

    <section class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <p class="text-xs uppercase tracking-wide text-slate-500">Total budget</p>
            <p class="mt-1 text-lg font-semibold tabular-nums">{{ $status['total_budget']->format() }}</p>
        </div>
        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <p class="text-xs uppercase tracking-wide text-slate-500">Terpakai (kategori ber-budget)</p>
            <p class="mt-1 text-lg font-semibold tabular-nums">{{ $status['total_actual_budgeted']->format() }}</p>
        </div>
        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <p class="text-xs uppercase tracking-wide text-slate-500">Pengeluaran tanpa budget</p>
            <p class="mt-1 text-lg font-semibold tabular-nums">{{ $status['unbudgeted_actual']->format() }}</p>
        </div>
    </section>

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="rounded-lg border border-slate-200 bg-white p-5 lg:col-span-2">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="font-semibold">Realisasi</h2>
                <button wire:click="copyPrevious" class="text-sm text-slate-500 underline hover:text-slate-900">Salin dari bulan lalu</button>
            </div>
            <ul class="divide-y divide-slate-100">
                @forelse ($status['rows'] as $row)
                    <li class="py-3" wire:key="b-{{ $row['category']->id }}">
                        <div class="mb-1.5 flex flex-wrap items-center justify-between gap-2 text-sm">
                            <span class="font-medium">{{ $row['category']->name }}</span>
                            <span class="tabular-nums">
                                {{ $row['actual']->format() }}
                                @if ($row['budget']) <span class="text-slate-400">/ {{ $row['budget']->format() }}</span> @endif
                            </span>
                        </div>
                        @if ($row['budget'])
                            @php [$bar, $icon, $label] = $stateStyle[$row['state']]; @endphp
                            <div class="h-2 overflow-hidden rounded bg-slate-100" role="progressbar" aria-valuenow="{{ $row['percent'] }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ $row['category']->name }}: {{ $row['percent'] }}% terpakai">
                                <div class="h-2 rounded-r {{ $bar }}" style="width: {{ min(100, $row['percent']) }}%"></div>
                            </div>
                            <div class="mt-1 flex items-center justify-between text-xs">
                                <span class="text-slate-600"><span aria-hidden="true">{{ $icon }}</span> {{ $label }} · {{ $row['percent'] }}%</span>
                                <span class="flex items-center gap-3">
                                    <span class="tabular-nums text-slate-500">{{ $row['remaining']->isNegative() ? 'lebih '.$row['remaining']->negate()->format() : 'sisa '.$row['remaining']->format() }}</span>
                                    <button wire:click="edit('{{ $row['category']->id }}', '{{ \App\Modules\Shared\Support\MoneyInput::format((string) $row['budget']->amount) }}')" class="text-slate-500 underline hover:text-slate-900">Ubah</button>
                                </span>
                            </div>
                        @else
                            <div class="flex items-center justify-between text-xs text-slate-500">
                                <span>Belum ada budget</span>
                                <button wire:click="edit('{{ $row['category']->id }}')" class="underline hover:text-slate-900">Atur budget</button>
                            </div>
                        @endif
                    </li>
                @empty
                    <li class="py-6 text-center text-sm text-slate-500">Belum ada budget atau pengeluaran di bulan ini.</li>
                @endforelse
            </ul>
        </section>

        <section class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="mb-3 font-semibold">Atur budget {{ $month->translatedFormat('F Y') }}</h2>
            <form wire:submit="save" class="space-y-3">
                <label class="block text-sm">
                    <span class="mb-1 block font-medium">Kategori pengeluaran</span>
                    <select wire:model="categoryId" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2">
                        <option value="">— pilih —</option>
                        @foreach ($expenseCategories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                    @error('categoryId') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm">
                    <span class="mb-1 block font-medium">Batas per bulan</span>
                    <input wire:model="amount" type="text" inputmode="decimal" placeholder="mis. 1.500.000 atau 2jt" class="w-full rounded-md border border-slate-300 px-3 py-2 tabular-nums">
                    <span class="mt-1 block text-xs text-slate-500">Isi 0 untuk menghapus budget.</span>
                    @error('amount') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>
                <button type="submit" class="w-full rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">Simpan budget</button>
            </form>
        </section>
    </div>
</div>
