<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Accounts\Models\FinancialAccount;
use App\Modules\Accounts\Policies\FinancialAccountPolicy;
use App\Modules\Interpretation\Contracts\TextInterpreter;
use App\Modules\Interpretation\Services\HeuristicTextInterpreter;
use App\Modules\Transactions\Models\Transaction;
use App\Modules\Transactions\Policies\TransactionPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Local pattern-based interpreter. Swap for an LLM-backed implementation only with user consent
        // and a vetted processor: entry text is personal financial data (ISO/IEC 27001 A.5.34, A.8.11).
        $this->app->bind(TextInterpreter::class, HeuristicTextInterpreter::class);
    }

    public function boot(): void
    {
        // Catch lazy-loading / silently-discarded attributes during development and tests.
        Model::shouldBeStrict(! $this->app->isProduction());

        Gate::policy(FinancialAccount::class, FinancialAccountPolicy::class);
        Gate::policy(Transaction::class, TransactionPolicy::class);

        Password::defaults(static fn () => Password::min(12)->letters()->mixedCase()->numbers());

        RateLimiter::for('auth', static fn (Request $request) => [
            Limit::perMinute(5)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(30)->by($request->ip()),
        ]);

        RateLimiter::for('api', static fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('posting', static fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));
    }
}
