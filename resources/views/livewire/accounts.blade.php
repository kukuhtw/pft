<div class="grid gap-6 lg:grid-cols-3">
    <section class="rounded-lg border border-slate-200 bg-white p-5 lg:col-span-2">
        <h1 class="mb-3 text-2xl font-semibold tracking-tight">Akun</h1>

        @if ($flash)
            <div class="mb-3 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ $flash }}</div>
        @endif

        <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                <tr><th class="py-2">Nama</th><th>Jenis</th><th>Pihak / Bank</th><th class="text-right">Saldo</th><th class="w-16"><span class="sr-only">Aksi</span></th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($accounts as $account)
                    <tr wire:key="acc-{{ $account->id }}" @class(['opacity-50' => ! $account->isActive(), 'bg-slate-50' => $editingId === $account->id])>
                        <td class="py-2 font-medium">{{ $account->name }} @if ($account->account_number_last4)<span class="text-xs text-slate-400">•••• {{ $account->account_number_last4 }}</span>@endif</td>
                        <td>{{ $account->accountType?->name ?? $account->account_type_code }}</td>
                        <td class="text-slate-500">{{ $account->counterparty?->name ?? $account->institution?->name ?? '—' }}</td>
                        <td @class(['text-right tabular-nums', 'text-amber-700' => $account->isLiability()])>{{ $account->balance()->format() }}</td>
                        <td class="text-right">
                            <button type="button" wire:click="edit('{{ $account->id }}')" class="rounded-md px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100">Ubah</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-6 text-center text-slate-500">Belum ada akun. Buat rekening, uang tunai/kas, atau e-wallet dulu.</td></tr>
                @endforelse
            </tbody>
        </table>
        <p class="mt-3 text-xs text-slate-500">Saldo hutang (pinjaman/kartu kredit) = jumlah yang masih harus dibayar.</p>

        @if ($editing)
            <form wire:submit="saveEdit" class="mt-5 space-y-4 rounded-lg border border-slate-200 p-4" wire:key="edit-{{ $editing->id }}">
                <h2 class="font-semibold">Ubah akun: {{ $editing->name }}</h2>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block text-sm">
                        <span class="mb-1 block font-medium">Nama akun</span>
                        <input wire:model="editName" type="text" maxlength="128" class="w-full rounded-md border border-slate-300 px-3 py-2">
                        @error('editName') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                    </label>
                    <label class="block text-sm">
                        <span class="mb-1 block font-medium">4 digit terakhir (opsional)</span>
                        <input wire:model="editLast4" type="text" inputmode="numeric" maxlength="4" class="w-full rounded-md border border-slate-300 px-3 py-2">
                        @error('editLast4') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                    </label>
                    <label class="block text-sm">
                        <span class="mb-1 block font-medium">Bank / e-wallet (opsional)</span>
                        <select wire:model="editInstitutionCode" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2">
                            <option value="">—</option>
                            @foreach ($institutions as $institution)
                                <option value="{{ $institution->code }}">{{ $institution->name }}</option>
                            @endforeach
                        </select>
                        @error('editInstitutionCode') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                    </label>
                    @if ($editing->isLiability())
                        <label class="block text-sm">
                            <span class="mb-1 block font-medium">Hutang kepada (pihak)</span>
                            <input wire:model="editCounterparty" type="text" maxlength="128" placeholder="mis. Pak Budi, Bank Jago" class="w-full rounded-md border border-slate-300 px-3 py-2">
                            <span class="mt-1 block text-xs text-slate-500">Kosongkan jika tidak ditautkan ke pihak tertentu.</span>
                            @error('editCounterparty') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                        </label>
                    @endif
                </div>

                <fieldset class="space-y-2 text-sm">
                    <legend class="mb-1 font-medium">Saldo</legend>
                    <label class="flex items-start gap-2">
                        <input type="radio" wire:model.live="balanceMode" value="none" class="mt-1">
                        <span>Jangan ubah saldo</span>
                    </label>
                    <label class="flex items-start gap-2">
                        <input type="radio" wire:model.live="balanceMode" value="opening" class="mt-1">
                        <span><span class="font-medium">Ubah saldo awal</span> — saldo awal saat ini {{ \App\Modules\Shared\Domain\Money::of((string) $editing->opening_balance, $editing->currency_code)->format() }}.
                            <span class="block text-xs text-slate-500">Untuk salah ketik saat setup. Selisihnya dicatat pada tanggal pembukaan akun.</span></span>
                    </label>
                    <label class="flex items-start gap-2">
                        <input type="radio" wire:model.live="balanceMode" value="today" class="mt-1">
                        <span><span class="font-medium">Sesuaikan saldo hari ini</span> — saldo tercatat {{ $editing->balance()->format() }}.
                            <span class="block text-xs text-slate-500">Isi saldo nyata sekarang (mis. sesuai mutasi bank / isi dompet). Selisihnya dicatat hari ini.</span></span>
                    </label>
                </fieldset>

                @if ($balanceMode !== 'none')
                    <label class="block text-sm">
                        <span class="mb-1 block font-medium">
                            {{ $balanceMode === 'opening' ? 'Saldo awal yang benar' : 'Saldo sebenarnya hari ini' }}{{ $editing->isLiability() ? ' (sisa hutang)' : '' }}
                        </span>
                        <input wire:model="targetBalance" type="text" inputmode="decimal" placeholder="mis. 1.500.000" class="w-full rounded-md border border-slate-300 px-3 py-2 tabular-nums">
                        @error('targetBalance') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                    </label>
                    <p class="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600">
                        Koreksi saldo <strong>tidak</strong> dihitung sebagai pemasukan atau pengeluaran, jadi laporan dan anggaran tidak berubah.
                        Riwayat lama tidak dihapus; koreksi tercatat sebagai transaksi "Penyesuaian" yang bisa dilacak.
                    </p>
                @endif

                @error('saveEdit') <p class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror

                <div class="flex gap-2">
                    <button type="submit" wire:loading.attr="disabled" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-50">Simpan perubahan</button>
                    <button type="button" wire:click="cancelEdit" class="rounded-md border border-slate-300 px-4 py-2 text-sm">Batal</button>
                </div>
            </form>
        @endif
    </section>

    <section class="rounded-lg border border-slate-200 bg-white p-5">
        <h2 class="mb-3 font-semibold">Tambah akun</h2>

        <form wire:submit="create" class="space-y-3">
            <label class="block text-sm">
                <span class="mb-1 block font-medium">Nama akun</span>
                <input wire:model="name" type="text" maxlength="128" placeholder="mis. BCA Gaji, Dompet, Kas Rumah" class="w-full rounded-md border border-slate-300 px-3 py-2">
                @error('name') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block text-sm">
                <span class="mb-1 block font-medium">Jenis</span>
                <select wire:model.live="accountType" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2">
                    @foreach ($types as $type)
                        <option value="{{ $type->code }}">{{ $type->name }}</option>
                    @endforeach
                </select>
                <span class="mt-1 block text-xs text-slate-500">Untuk uang tunai di dompet/kas, pilih "Uang Tunai / Kas (dompet fisik)".</span>
                @error('accountType') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block text-sm">
                <span class="mb-1 block font-medium">Bank / e-wallet (opsional)</span>
                <select wire:model="institutionCode" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2">
                    <option value="">—</option>
                    @foreach ($institutions as $institution)
                        <option value="{{ $institution->code }}">{{ $institution->name }}</option>
                    @endforeach
                </select>
            </label>

            @if ($isLiability)
                <label class="block text-sm">
                    <span class="mb-1 block font-medium">Hutang kepada (pihak)</span>
                    <input wire:model="counterparty" type="text" maxlength="128" placeholder="mis. Pak Budi, Bank Jago" class="w-full rounded-md border border-slate-300 px-3 py-2">
                </label>
            @endif

            <label class="block text-sm">
                <span class="mb-1 block font-medium">{{ $isLiability ? 'Sisa hutang saat ini' : 'Saldo awal' }} (opsional)</span>
                <input wire:model="openingBalance" type="text" inputmode="decimal" placeholder="mis. 1.500.000" class="w-full rounded-md border border-slate-300 px-3 py-2 tabular-nums">
                <span class="mt-1 block text-xs text-slate-500">Salah isi? Bisa diubah nanti lewat tombol "Ubah".</span>
                @error('openingBalance') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block text-sm">
                <span class="mb-1 block font-medium">4 digit terakhir no. rekening/kartu (opsional)</span>
                <input wire:model="last4" type="text" inputmode="numeric" maxlength="4" class="w-full rounded-md border border-slate-300 px-3 py-2">
                <span class="mt-1 block text-xs text-slate-500">Demi keamanan, nomor lengkap tidak pernah disimpan.</span>
                @error('last4') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
            </label>

            @error('create') <p class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror

            <button type="submit" wire:loading.attr="disabled" class="w-full rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-50">Buat akun</button>
        </form>
    </section>
</div>
