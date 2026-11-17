<div class="space-y-4">
    <h1 class="text-2xl font-semibold tracking-tight">Transaksi</h1>

    @if ($flash)
        <div class="rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ $flash }}</div>
    @endif
    @error('reverse') <div class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ $message }}</div> @enderror

    <div class="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-5">
        <label class="block text-sm">
            <span class="mb-1 block font-medium">Jenis</span>
            <select wire:model.live="intent" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2">
                <option value="">Semua</option>
                @foreach ($intents as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </select>
        </label>
        <label class="block text-sm">
            <span class="mb-1 block font-medium">Pihak</span>
            <select wire:model.live="counterpartyId" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2">
                <option value="">Semua</option>
                @foreach ($counterparties as $c)
                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="block text-sm">
            <span class="mb-1 block font-medium">Kategori</span>
            <select wire:model.live="categoryId" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2">
                <option value="">Semua</option>
                @foreach ($categories as $c)
                    <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->kind->value === 'INCOME' ? 'masuk' : 'keluar' }})</option>
                @endforeach
            </select>
        </label>
        <label class="block text-sm">
            <span class="mb-1 block font-medium">Dari tanggal</span>
            <input wire:model.live="dateFrom" type="date" class="w-full rounded-md border border-slate-300 px-3 py-2">
        </label>
        <label class="block text-sm">
            <span class="mb-1 block font-medium">Sampai tanggal</span>
            <input wire:model.live="dateTo" type="date" class="w-full rounded-md border border-slate-300 px-3 py-2">
        </label>
    </div>

    <section class="rounded-lg border border-slate-200 bg-white px-5">
        <ul class="divide-y divide-slate-100">
            @forelse ($transactions as $t)
                <li class="flex items-center gap-3" wire:key="tx-{{ $t->id }}">
                    <div class="min-w-0 flex-1">
                        @include('livewire.partials.transaction-row', ['t' => $t])
                    </div>
                    @if ($t->status === $posted && $t->type->value !== 'ADJUSTMENT')
                        <button wire:click="reverse('{{ $t->id }}')"
                                wire:confirm="Batalkan transaksi ini? Pembukuan akan mencatat jurnal pembalik; data asli tetap tersimpan."
                                class="shrink-0 rounded border border-slate-300 px-2 py-1 text-xs text-slate-600 hover:bg-slate-100">Batalkan</button>
                    @endif
                </li>
            @empty
                <li class="py-6 text-center text-sm text-slate-500">Tidak ada transaksi untuk filter ini.</li>
            @endforelse
        </ul>
    </section>

    {{ $transactions->links() }}
</div>
