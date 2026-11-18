<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the static user guides in /documentation (tutorial, Telegram guide, their CSS and screenshots)
 * at /dokumentasi/… so the landing page can link to them. Public: the guides hold no user data and
 * every screenshot uses fictitious sample data.
 *
 * Only plain file names with a known extension are served, resolved inside the documentation folder
 * (no traversal, no hidden files, no PHP).
 */
final class DocumentationController extends Controller
{
    private const array TYPES = [
        'html' => 'text/html; charset=UTF-8',
        'css' => 'text/css; charset=UTF-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
    ];

    public function __invoke(string $path = 'tutorial.html'): BinaryFileResponse
    {
        if (preg_match('#^(?:images/)?[A-Za-z0-9][A-Za-z0-9_-]*\.([a-z]{2,4})$#', $path, $m) !== 1 || ! isset(self::TYPES[$m[1]])) {
            throw new NotFoundHttpException;
        }

        $root = realpath(base_path('documentation'));
        $file = realpath(base_path('documentation/'.$path));

        if ($root === false || $file === false || ! is_file($file) || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR)) {
            throw new NotFoundHttpException;
        }

        return response()->file($file, [
            'Content-Type' => self::TYPES[$m[1]],
            'Cache-Control' => 'public, max-age=600',
            'X-Content-Type-Options' => 'nosniff',
            // The guides need only their own CSS (plus a few inline colour dots), images and a small inline theme script.
            'Content-Security-Policy' => "default-src 'none'; style-src 'self' 'unsafe-inline'; img-src 'self'; script-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
        ]);
    }
}
