<div>
    <h1 class="mb-4 text-xl font-semibold">Masuk</h1>

    <form wire:submit="login" class="space-y-4">
        <div>
            <label for="email" class="mb-1 block text-sm font-medium">Email</label>
            <input wire:model="email" id="email" type="email" autocomplete="username" required autofocus
                   class="w-full rounded-md border border-slate-300 px-3 py-2 focus:border-slate-900 focus:outline-none">
            @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div x-data="{ show: false }">
            <label for="password" class="mb-1 block text-sm font-medium">Kata sandi</label>
            <div class="relative">
                <input wire:model="password" id="password" type="password" x-bind:type="show ? 'text' : 'password'"
                       autocomplete="current-password" required
                       class="w-full rounded-md border border-slate-300 py-2 pr-11 pl-3 focus:border-slate-900 focus:outline-none">
                <button type="button" x-on:click="show = ! show"
                        x-bind:aria-pressed="show.toString()"
                        x-bind:aria-label="show ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'"
                        x-bind:title="show ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'"
                        aria-controls="password" aria-label="Tampilkan kata sandi"
                        class="absolute inset-y-0 right-0 flex w-10 items-center justify-center rounded-r-md text-slate-500 hover:text-slate-900 focus-visible:outline-2 focus-visible:outline-slate-900">
                    {{-- eye --}}
                    <svg x-show="! show" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="size-5" aria-hidden="true">
                        <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>
                    </svg>
                    {{-- eye-off --}}
                    <svg x-show="show" x-cloak xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="size-5" aria-hidden="true">
                        <path d="M10.7 5.1A10.8 10.8 0 0 1 12 5c6.5 0 10 7 10 7a17.6 17.6 0 0 1-2.2 3.2M6.6 6.6C3.7 8.5 2 12 2 12s3.5 7 10 7a10 10 0 0 0 5.4-1.6"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/><path d="m2 2 20 20"/>
                    </svg>
                </button>
            </div>
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
