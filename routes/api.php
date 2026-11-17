<?php

declare(strict_types=1);

use App\Http\Middleware\RequireIdempotencyKey;
use App\Modules\Accounts\Http\Controllers\FinancialAccountController;
use App\Modules\Categories\Http\Controllers\CategoryController;
use App\Modules\Counterparties\Http\Controllers\CounterpartyController;
use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Reporting\Http\Controllers\ReportController;
use App\Modules\Transactions\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::middleware('throttle:auth')->group(function (): void {
        Route::post('auth/register', [AuthController::class, 'register']);
        Route::post('auth/login', [AuthController::class, 'login']);
    });

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);

        Route::get('accounts', [FinancialAccountController::class, 'index']);
        Route::post('accounts', [FinancialAccountController::class, 'store']);
        Route::get('accounts/{id}', [FinancialAccountController::class, 'show']);

        Route::get('categories', [CategoryController::class, 'index']);

        Route::get('counterparties', [CounterpartyController::class, 'index']);

        Route::get('transactions', [TransactionController::class, 'index']);
        // Read-only interpretation of free text; registered before {id} so "interpret" is never an ID.
        Route::post('transactions/interpret', [TransactionController::class, 'interpret'])->middleware('throttle:posting');
        Route::get('transactions/{id}', [TransactionController::class, 'show']);

        Route::middleware(['throttle:posting', RequireIdempotencyKey::class])->group(function (): void {
            Route::post('transactions/income', [TransactionController::class, 'income']);
            Route::post('transactions/expense', [TransactionController::class, 'expense']);
            Route::post('transactions/transfer', [TransactionController::class, 'transfer']);
            Route::post('transactions/loan-received', [TransactionController::class, 'loanReceived']);
            Route::post('transactions/debt-payment', [TransactionController::class, 'debtPayment']);
            Route::post('transactions/{id}/reverse', [TransactionController::class, 'reverse']);
        });

        Route::get('reports/summary', [ReportController::class, 'summary']);
    });
});
