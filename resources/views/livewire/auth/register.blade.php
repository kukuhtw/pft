<div>
    <h1 class="mb-4 text-xl font-semibold">Daftar</h1>

    <form wire:submit="register" class="space-y-4">
        @foreach ([
            ['name', 'Nama', 'text', 'name'],
            ['email', 'Email', 'email', 'username'],
            ['password', 'Kata sandi', 'password', 'new-password'],
            ['password_confirmation', 'Ulangi kata sandi', 'password', 'new-password'],
        ] as [$field, $label, $type, $autocomplete])
            <div>
                <label for="{{ $field }}" class="mb-1 block text-sm font-medium">{{ $label }}</label>
                <input wire:model="{{ $field }}" id="{{ $field }}" type="{{ $type }}" autocomplete="{{ $autocomplete }}" required
                       class="w-full rounded-md border border-slate-300 px-3 py-2 focus:border-slate-900 focus:outline-none">
                @error($field) <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        @endforeach

        <p class="text-xs text-slate-500">Minimal 12 karakter, huruf besar &amp; kecil, dan angka.</p>

        <button type="submit" wire:loading.attr="disabled"
                class="w-full rounded-md bg-slate-900 px-4 py-2 font-medium text-white hover:bg-slate-700 disabled:opacity-50">
            Buat akun
        </button>
    </form>

    <p class="mt-4 text-center text-sm text-slate-500">
        Sudah punya akun? <a href="{{ route('login') }}" class="font-medium text-slate-900 underline">Masuk</a>
    </p>
</div>
