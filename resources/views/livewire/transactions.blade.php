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
                <li wire:key="tx-{{ $t->id }}">
                    <div class="flex items-center gap-3">
                        <div class="min-w-0 flex-1">
                            @include('livewire.partials.transaction-row', ['t' => $t])
                        </div>
                        @if (\App\Modules\Transactions\Services\TransactionAmendmentService::editable($t))
                            <button wire:click="edit('{{ $t->id }}')" class="shrink-0 rounded border border-slate-300 px-2 py-1 text-xs text-slate-700 hover:bg-slate-100">Ubah</button>
                        @endif
                        @if ($t->status === $posted && $t->type->value !== 'ADJUSTMENT')
                            <button wire:click="reverse('{{ $t->id }}')"
                                    wire:confirm="Batalkan transaksi ini? Pembukuan akan mencatat jurnal pembalik; data asli tetap tersimpan."
                                    class="shrink-0 rounded border border-slate-300 px-2 py-1 text-xs text-slate-600 hover:bg-slate-100">Batalkan</button>
                        @endif
                    </div>

                    @if ($editing && $editing->id === $t->id)
                        <form wire:submit="saveEdit" class="mb-3 space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-4">
                            <p class="text-sm font-semibold">Ubah {{ mb_strtolower($editing->intent->label()) }}</p>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <label class="block text-sm">
                                    <span class="mb-1 block font-medium">Nominal</span>
                                    <input wire:model="editAmount" type="text" inputmode="decimal" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 tabular-nums">
                                    @error('editAmount') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                                </label>
                                <label class="block text-sm">
                                    <span class="mb-1 block font-medium">Tanggal</span>
                                    <input wire:model="editDate" type="date" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2">
                                    @error('editDate') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                                </label>
                                @if ($editNeeds['source'])
                                    <label class="block text-sm">
                                        <span class="mb-1 block font-medium">{{ $editing->intent->value === 'EXPENSE' ? 'Pos pengeluaran (dibayar dari)' : 'Dari akun' }}</span>
                                        <select wire:model="editSourceId" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2">
                                            <option value="">— pilih —</option>
                                            @foreach ($editSources as $a)
                                                <option value="{{ $a->id }}">{{ $a->name }}</option>
                                            @endforeach
                                        </select>
                                        @error('editSourceId') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                                    </label>
                                @endif
                                @if ($editNeeds['destination'])
                                    <label class="block text-sm">
                                        <span class="mb-1 block font-medium">Masuk ke akun</span>
                                        <select wire:model="editDestinationId" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2">
                                            <option value="">— pilih —</option>
                                            @foreach ($editDestinations as $a)
                                                <option value="{{ $a->id }}">{{ $a->name }}</option>
                                            @endforeach
                                        </select>
                                        @error('editDestinationId') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                                    </label>
                                @endif
                                @if ($editNeeds['category'])
                                    <label class="block text-sm">
                                        <span class="mb-1 block font-medium">Kategori</span>
                                        <select wire:model="editCategoryId" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2">
                                            <option value="">— pilih —</option>
                                            @foreach ($editCategories as $c)
                                                <option value="{{ $c->id }}">{{ $c->name }}</option>
                                            @endforeach
                                        </select>
                                        @error('editCategoryId') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                                    </label>
                                @endif
                                <label class="block text-sm sm:col-span-2">
                                    <span class="mb-1 block font-medium">Deskripsi</span>
                                    <input wire:model="editDescription" type="text" maxlength="255" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2">
                                    @error('editDescription') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                                </label>
                            </div>
                            <p class="text-xs text-slate-500">Demi jejak audit, versi lama tidak dihapus: versi lama dibatalkan dengan jurnal pembalik dan versi baru dicatat, dalam satu langkah. Saldo dan laporan langsung mengikuti versi baru.</p>
                            @error('saveEdit') <p class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                            <div class="flex gap-2">
                                <button type="submit" wire:loading.attr="disabled" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-50">Simpan perubahan</button>
                                <button type="button" wire:click="cancelEdit" class="rounded-md border border-slate-300 px-4 py-2 text-sm">Batal</button>
                            </div>
                        </form>
                    @endif
                </li>
            @empty
                <li class="py-6 text-center text-sm text-slate-500">Tidak ada transaksi untuk filter ini.</li>
            @endforelse
        </ul>
    </section>

    {{ $transactions->links() }}
</div>
