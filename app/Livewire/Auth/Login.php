<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Services\AccessPolicy;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Session login for the web dashboard. Same protections as the API login:
 * 5 attempts/min per email+IP and 30/min per IP, one generic error message (no account enumeration),
 * session regeneration (no fixation) and audit logging of success and failure.
 */
#[Layout('layouts::guest')]
#[Title('Masuk')]
final class Login extends Component
{
    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    public function login(AuditLogger $audit): void
    {
        $this->email = trim($this->email);
        $this->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = mb_strtolower(trim($this->email));
        $keys = ['web-login:'.$email.'|'.request()->ip() => 5, 'web-login-ip:'.request()->ip() => 30];

        foreach ($keys as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages([
                    'email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key), 'minutes' => ceil(RateLimiter::availableIn($key) / 60)]),
                ]);
            }
        }

        // An email outside AUTH_ALLOWED_EMAILS fails exactly like a wrong password (no enumeration).
        if (! AccessPolicy::emailAllowed($email) || ! Auth::attempt(['email' => $email, 'password' => $this->password], $this->remember)) {
            foreach (array_keys($keys) as $key) {
                RateLimiter::hit($key, 60);
            }
            $audit->record('auth.login_failed', User::query()->where('email', $email)->first(), null, ['channel' => 'WEB']);
            $this->reset('password');

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        RateLimiter::clear(array_key_first($keys));
        session()->regenerate();

        /** @var User $user */
        $user = Auth::user();
        $audit->record('auth.login', $user, $user, ['channel' => 'WEB']);

        $this->redirectIntended(route('dashboard'), navigate: false);
    }

    public function render(): View
    {
        return view('livewire.auth.login', ['registrationEnabled' => AccessPolicy::registrationEnabled()]);
    }
}
