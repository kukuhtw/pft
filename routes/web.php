<?php

declare(strict_types=1);

use App\Http\Controllers\Web\ExportController;
use App\Http\Controllers\Web\LogoutController;
use App\Livewire\Accounts;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\Budgets;
use App\Livewire\Categories;
use App\Livewire\Dashboard;
use App\Livewire\Profile;
use App\Livewire\Reports;
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
    Route::livewire('/categories', Categories::class)->name('categories');
    Route::livewire('/budgets', Budgets::class)->name('budgets');
    Route::livewire('/reports', Reports::class)->name('reports');
    Route::livewire('/profile', Profile::class)->name('profile');
    Route::post('/logout', LogoutController::class)->name('logout');

    Route::middleware('throttle:10,1')->group(function (): void {
        Route::get('/export/transactions.csv', [ExportController::class, 'transactionsCsv'])->name('export.transactions');
        Route::get('/export/my-data.json', [ExportController::class, 'myData'])->name('export.my-data');
    });
});
