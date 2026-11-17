<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen items-center justify-center bg-slate-50 px-4 text-slate-900 antialiased">
    <main class="w-full max-w-sm">
        <p class="mb-6 text-center text-lg font-semibold">💰 {{ config('app.name') }}</p>
        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            {{ $slot }}
        </div>
    </main>
</body>
</html>
