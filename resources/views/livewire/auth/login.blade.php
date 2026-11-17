<div>
    <h1 class="mb-4 text-xl font-semibold">Masuk</h1>

    <form wire:submit="login" class="space-y-4">
        <div>
            <label for="email" class="mb-1 block text-sm font-medium">Email</label>
            <input wire:model="email" id="email" type="email" autocomplete="username" required autofocus
                   class="w-full rounded-md border border-slate-300 px-3 py-2 focus:border-slate-900 focus:outline-none">
            @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password" class="mb-1 block text-sm font-medium">Kata sandi</label>
            <input wire:model="password" id="password" type="password" autocomplete="current-password" required
                   class="w-full rounded-md border border-slate-300 px-3 py-2 focus:border-slate-900 focus:outline-none">
            @error('password') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <label class="flex items-center gap-2 text-sm">
            <input wire:model="remember" type="checkbox" class="rounded border-slate-300"> Ingat saya
        </label>

        <button type="submit" wire:loading.attr="disabled"
                class="w-full rounded-md bg-slate-900 px-4 py-2 font-medium text-white hover:bg-slate-700 disabled:opacity-50">
            Masuk
        </button>
    </form>

    @if ($registrationEnabled)
        <p class="mt-4 text-center text-sm text-slate-500">
            Belum punya akun? <a href="{{ route('register') }}" class="font-medium text-slate-900 underline">Daftar</a>
        </p>
    @endif
</div>
