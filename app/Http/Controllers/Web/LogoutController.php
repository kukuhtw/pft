<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class LogoutController extends Controller
{
    public function __invoke(Request $request, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();
        if ($user !== null) {
            $audit->record('auth.logout', $user, $user, ['channel' => 'WEB']);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
