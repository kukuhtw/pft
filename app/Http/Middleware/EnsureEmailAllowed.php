<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Services\AccessPolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Removing an email from AUTH_ALLOWED_EMAILS cuts access on the next request:
 * web sessions are logged out, API tokens of that user are refused.
 */
final class EnsureEmailAllowed
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! AccessPolicy::emailAllowed($user->email)) {
            $this->audit->record('auth.access_revoked', $user, $user, ['reason' => 'email_not_allowed']);

            if ($request->is('api/*') || $request->expectsJson()) {
                abort(403);
            }

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        return $next($request);
    }
}
