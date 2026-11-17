<?php

use App\Modules\Identity\Console\DashboardUserCommand;
use App\Modules\Ledger\Console\VerifyLedgerCommand;
use App\Modules\Shared\Console\PruneFinanceDataCommand;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Telegram\Console\TelegramWebhookCommand;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

$wantsJson = static fn (Request $request): bool => $request->is('api/*') || $request->expectsJson();

$error = static fn (string $code, string $message, int $status, array $details = []) => response()->json(
    ['error' => array_filter(['code' => $code, 'message' => $message, 'details' => $details ?: null], static fn ($v) => $v !== null)],
    $status,
);

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        VerifyLedgerCommand::class,
        PruneFinanceDataCommand::class,
        TelegramWebhookCommand::class,
        DashboardUserCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind Dokploy's Traefik (or any reverse proxy): trust X-Forwarded-* only from these
        // comma-separated IPs/CIDRs so client IPs (rate limits, audit logs) and HTTPS are correct.
        if (is_string($proxies = env('TRUSTED_PROXIES')) && $proxies !== '') {
            $middleware->trustProxies(at: $proxies);
        }
    })
    ->withExceptions(function (Exceptions $exceptions) use ($wantsJson, $error): void {
        $exceptions->shouldRenderJsonWhen($wantsJson);

        // Business-rule violations: {"error": {"code", "message", "details"}}
        $exceptions->render(fn (DomainException $e, Request $r) => $wantsJson($r)
            ? $error($e->errorCode(), $e->getMessage(), $e->httpStatus(), $e->details())
            : null);

        $exceptions->render(fn (ValidationException $e, Request $r) => $wantsJson($r)
            ? $error('VALIDATION_FAILED', $e->getMessage(), 422, $e->errors())
            : null);

        $exceptions->render(fn (AuthenticationException $e, Request $r) => $wantsJson($r)
            ? $error('UNAUTHENTICATED', 'Unauthenticated.', 401)
            : null);

        // All other HTTP errors (model-not-found, policy denyAsNotFound → 404, throttling → 429…).
        // Never reveal model class names or IDs of missing — or other tenants' — resources.
        $exceptions->render(fn (HttpExceptionInterface $e, Request $r) => $wantsJson($r)
            ? match ($e->getStatusCode()) {
                404 => $error('NOT_FOUND', 'Resource not found.', 404),
                403 => $error('FORBIDDEN', 'This action is unauthorized.', 403),
                429 => $error('RATE_LIMITED', 'Too many requests.', 429)->withHeaders($e->getHeaders()),
                405 => $error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405),
                default => $error('HTTP_ERROR', $e->getStatusCode() >= 500 ? 'Server error.' : ($e->getMessage() ?: 'HTTP error.'), $e->getStatusCode()),
            }
            : null);
    })->create();
