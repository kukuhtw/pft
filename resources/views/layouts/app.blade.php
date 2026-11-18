<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen overflow-x-hidden bg-slate-50 text-slate-900 antialiased">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-3">
            <a href="{{ route('dashboard') }}" wire:navigate class="font-semibold tracking-tight">💰 {{ config('app.name') }}</a>
            <nav class="order-last flex w-full min-w-0 items-center gap-1 overflow-x-auto text-sm whitespace-nowrap md:order-none md:w-auto">
                @foreach ([['dashboard', 'Dashboard'], ['transactions', 'Transaksi'], ['debts', 'Hutang'], ['budgets', 'Budget'], ['reports', 'Laporan'], ['accounts', 'Akun'], ['categories', 'Kategori'], ['telegram.guide', 'Telegram']] as [$route, $label])
                    <a href="{{ route($route) }}" wire:navigate
                       @class(['rounded-md px-3 py-1.5', 'bg-slate-900 text-white' => request()->routeIs($route), 'text-slate-600 hover:bg-slate-100' => ! request()->routeIs($route)])>{{ $label }}</a>
                @endforeach
            </nav>
            <div class="flex items-center gap-3 text-sm">
                <a href="{{ route('profile') }}" wire:navigate class="text-slate-500 hover:underline">{{ auth()->user()->name }}</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="rounded-md border border-slate-300 px-3 py-1.5 hover:bg-slate-100">Keluar</button>
                </form>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-6">
        {{ $slot }}
    </main>
</body>
</html>
