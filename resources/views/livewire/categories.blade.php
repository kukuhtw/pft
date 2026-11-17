<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-semibold tracking-tight">Kategori</h1>
        <p class="text-sm text-slate-500">Kategori yang disembunyikan tidak muncul di input baru, tapi riwayat dan laporannya tetap ada.</p>
    </div>

    @if ($flash)
        <div class="rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ $flash }}</div>
    @endif
    @error('category') <div class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ $message }}</div> @enderror

    <form wire:submit="create" class="flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-4">
        <label class="min-w-48 flex-1 text-sm">
            <span class="mb-1 block font-medium">Kategori baru</span>
            <input wire:model="newName" type="text" maxlength="128" placeholder="mis. Jualan Kue, Langganan Streaming" class="w-full rounded-md border border-slate-300 px-3 py-2">
            @error('newName') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
        </label>
        <label class="text-sm">
            <span class="mb-1 block font-medium">Jenis</span>
            <select wire:model="newKind" class="rounded-md border border-slate-300 bg-white px-3 py-2">
                <option value="EXPENSE">Pengeluaran</option>
                <option value="INCOME">Pemasukan</option>
            </select>
        </label>
        <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">Tambah</button>
    </form>

    <div class="grid gap-6 lg:grid-cols-2">
        @foreach (['EXPENSE' => 'Pengeluaran', 'INCOME' => 'Pemasukan'] as $kind => $label)
            <section class="rounded-lg border border-slate-200 bg-white p-5">
                <h2 class="mb-3 font-semibold">{{ $label }}</h2>
                <ul class="divide-y divide-slate-100 text-sm">
                    @foreach ($groups[$kind] ?? [] as $category)
                        <li class="flex items-center justify-between gap-3 py-2" wire:key="cat-{{ $category->id }}">
                            @if ($editingId === $category->id)
                                <form wire:submit="rename" class="flex flex-1 gap-2">
                                    <input wire:model="editingName" type="text" maxlength="128" class="min-w-0 flex-1 rounded-md border border-slate-300 px-2 py-1" aria-label="Nama kategori">
                                    <button type="submit" class="rounded-md bg-slate-900 px-3 py-1 text-xs text-white">Simpan</button>
                                    <button type="button" wire:click="$set('editingId', '')" class="text-xs text-slate-500 underline">Batal</button>
                                </form>
                            @else
                                <span @class(['min-w-0 truncate', 'text-slate-400 line-through' => ! $category->is_active])>
                                    {{ $category->name }}
                                    @if (! $category->is_system) <span class="ml-1 rounded bg-slate-100 px-1.5 text-xs text-slate-500">buatan Anda</span> @endif
                                </span>
                                <span class="flex shrink-0 items-center gap-3">
                                    @isset($totals[$category->id])
                                        <a href="{{ route('transactions', ['categoryId' => $category->id]) }}" wire:navigate class="tabular-nums text-slate-500 hover:underline" title="Bulan ini">{{ $totals[$category->id]->format() }}</a>
                                    @endisset
                                    <button wire:click="edit('{{ $category->id }}')" class="text-xs text-slate-500 underline hover:text-slate-900">Ganti nama</button>
                                    @unless (in_array($category->code, $fallbacks, true))
                                        <button wire:click="toggle('{{ $category->id }}')" class="text-xs text-slate-500 underline hover:text-slate-900">{{ $category->is_active ? 'Sembunyikan' : 'Aktifkan' }}</button>
                                    @endunless
                                </span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>
</div>
