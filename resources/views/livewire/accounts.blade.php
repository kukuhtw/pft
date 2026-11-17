<div class="grid gap-6 lg:grid-cols-3">
    <section class="rounded-lg border border-slate-200 bg-white p-5 lg:col-span-2">
        <h1 class="mb-3 text-2xl font-semibold tracking-tight">Akun</h1>
        <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                <tr><th class="py-2">Nama</th><th>Jenis</th><th>Pihak / Bank</th><th class="text-right">Saldo</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($accounts as $account)
                    <tr wire:key="acc-{{ $account->id }}" @class(['opacity-50' => ! $account->isActive()])>
                        <td class="py-2 font-medium">{{ $account->name }} @if ($account->account_number_last4)<span class="text-xs text-slate-400">•••• {{ $account->account_number_last4 }}</span>@endif</td>
                        <td>{{ $account->account_type_code }}</td>
                        <td class="text-slate-500">{{ $account->counterparty?->name ?? $account->institution?->name ?? '—' }}</td>
                        <td @class(['text-right tabular-nums', 'text-amber-700' => $account->isLiability()])>{{ $account->balance()->format() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-6 text-center text-slate-500">Belum ada akun. Buat rekening, dompet tunai, atau e-wallet dulu.</td></tr>
                @endforelse
            </tbody>
        </table>
        <p class="mt-3 text-xs text-slate-500">Saldo hutang (pinjaman/kartu kredit) = jumlah yang masih harus dibayar.</p>
    </section>

    <section class="rounded-lg border border-slate-200 bg-white p-5">
        <h2 class="mb-3 font-semibold">Tambah akun</h2>

        @if ($flash)
            <div class="mb-3 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ $flash }}</div>
        @endif

        <form wire:submit="create" class="space-y-3">
            <label class="block text-sm">
                <span class="mb-1 block font-medium">Nama akun</span>
                <input wire:model="name" type="text" maxlength="128" placeholder="mis. BCA Gaji" class="w-full rounded-md border border-slate-300 px-3 py-2">
                @error('name') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block text-sm">
                <span class="mb-1 block font-medium">Jenis</span>
                <select wire:model.live="accountType" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2">
                    @foreach ($types as $type)
                        <option value="{{ $type->code }}">{{ $type->name }}</option>
                    @endforeach
                </select>
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
