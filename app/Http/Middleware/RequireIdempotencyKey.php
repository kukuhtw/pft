<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Financial posting endpoints must carry an `Idempotency-Key` header (8–128 chars, [A-Za-z0-9_.:-]).
 */
final class RequireIdempotencyKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || preg_match('/^[A-Za-z0-9_.:\-]{8,128}$/', $key) !== 1) {
            return response()->json([
                'error' => [
                    'code' => 'IDEMPOTENCY_KEY_REQUIRED',
                    'message' => 'Header Idempotency-Key wajib diisi (8–128 karakter: huruf, angka, _ . : -).',
                ],
            ], 400);
        }

        return $next($request);
    }
}
