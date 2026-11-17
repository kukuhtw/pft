<?php

declare(strict_types=1);

use App\Http\Controllers\Web\LogoutController;
use App\Livewire\Accounts;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\Dashboard;
use App\Livewire\Transactions;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function (): void {
    Route::livewire('/login', Login::class)->name('login');
    Route::livewire('/register', Register::class)->name('register');
});

Route::middleware('auth')->group(function (): void {
    Route::livewire('/dashboard', Dashboard::class)->name('dashboard');
    Route::livewire('/transactions', Transactions::class)->name('transactions');
    Route::livewire('/accounts', Accounts::class)->name('accounts');
    Route::post('/logout', LogoutController::class)->name('logout');
});
