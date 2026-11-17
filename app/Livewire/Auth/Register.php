<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Services\AccessPolicy;
use App\Modules\Identity\Services\UserProvisioningService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::guest')]
#[Title('Daftar')]
final class Register extends Component
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        abort_unless(AccessPolicy::registrationEnabled(), 404);
    }

    public function register(UserProvisioningService $provisioning, AuditLogger $audit): void
    {
        abort_unless(AccessPolicy::registrationEnabled(), 404);

        $key = 'web-register:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key), 'minutes' => 1])]);
        }
        RateLimiter::hit($key, 60);

        $this->email = mb_strtolower(trim($this->email));
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ]);

        if (! AccessPolicy::emailAllowed($data['email'])) {
            throw ValidationException::withMessages(['email' => 'Email ini tidak diizinkan mendaftar. Hubungi pengelola aplikasi.']);
        }

        $user = $provisioning->register($data['name'], $data['email'], $data['password']);

        Auth::login($user);
        session()->regenerate();
        $audit->record('auth.login', $user, $user, ['channel' => 'WEB']);

        $this->redirectRoute('dashboard');
    }

    public function render(): View
    {
        return view('livewire.auth.register');
    }
}
