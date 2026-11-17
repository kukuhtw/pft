<div class="mx-auto max-w-2xl space-y-6">
    <h1 class="text-2xl font-semibold tracking-tight">Profil</h1>

    @if ($flash)
        <div class="rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ $flash }}</div>
    @endif

    <section class="rounded-lg border border-slate-200 bg-white p-5">
        <h2 class="mb-3 font-semibold">Data diri</h2>
        <form wire:submit="saveName" class="space-y-3">
            <label class="block text-sm">
                <span class="mb-1 block font-medium">Nama</span>
                <input wire:model="name" type="text" maxlength="255" autocomplete="name" class="w-full rounded-md border border-slate-300 px-3 py-2">
                @error('name') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
            </label>
            <p class="text-sm text-slate-500">Email: {{ $email }}</p>
            <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">Simpan</button>
        </form>
    </section>

    <section class="rounded-lg border border-slate-200 bg-white p-5">
        <h2 class="mb-1 font-semibold">Ganti kata sandi</h2>
        <p class="mb-3 text-sm text-slate-500">Setelah diganti, semua perangkat lain akan logout dan semua token API dicabut.</p>
        <form wire:submit="changePassword" class="space-y-3">
            @foreach ([['current_password', 'Kata sandi saat ini', 'current-password'], ['password', 'Kata sandi baru', 'new-password'], ['password_confirmation', 'Ulangi kata sandi baru', 'new-password']] as [$field, $label, $autocomplete])
                <label class="block text-sm">
                    <span class="mb-1 block font-medium">{{ $label }}</span>
                    <input wire:model="{{ $field }}" type="password" autocomplete="{{ $autocomplete }}" class="w-full rounded-md border border-slate-300 px-3 py-2">
                    @error($field) <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>
            @endforeach
            <p class="text-xs text-slate-500">Minimal 12 karakter, huruf besar &amp; kecil, dan angka.</p>
            <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">Ganti kata sandi</button>
        </form>
    </section>

    <section class="rounded-lg border border-slate-200 bg-white p-5">
        <h2 class="mb-1 font-semibold">Data saya</h2>
        <p class="mb-3 text-sm text-slate-500">Unduh semua data yang disimpan aplikasi tentang Anda (akun, kategori, pihak, budget, transaksi) dalam format JSON. Kata sandi dan token tidak ikut diunduh.</p>
        <a href="{{ route('export.my-data') }}" class="inline-block rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100">⬇ Unduh data saya</a>
    </section>
</div>
