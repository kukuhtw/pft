<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Profil')]
final class Profile extends Component
{
    public string $name = '';

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public ?string $flash = null;

    public function mount(): void
    {
        $this->name = $this->user()->name;
    }

    public function saveName(AuditLogger $audit): void
    {
        $this->validate(['name' => ['required', 'string', 'max:255']], [], ['name' => 'nama']);
        $user = $this->user();
        $user->update(['name' => trim($this->name)]);
        $audit->record('user.profile_updated', $user, $user, ['fields' => ['name']]);
        $this->flash = 'Nama diperbarui.';
    }

    /**
     * Changing the password signs out every other session and revokes all API tokens,
     * so a leaked password or token stops working immediately (ISO/IEC 27001 A.5.17 / A.8.5).
     */
    public function changePassword(AuditLogger $audit): void
    {
        $this->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed', 'different:current_password'],
        ], [], ['current_password' => 'kata sandi saat ini', 'password' => 'kata sandi baru']);

        $user = $this->user();

        DB::transaction(function () use ($user): void {
            $user->forceFill(['password' => Hash::make($this->password)])->save();
            $user->tokens()->delete();

            if (config('session.driver') === 'database') {
                DB::table((string) config('session.table', 'sessions'))
                    ->where('user_id', $user->id)
                    ->where('id', '!=', session()->getId())
                    ->delete();
            }
        });

        session()->regenerate();
        $audit->record('auth.password_changed', $user, $user, ['channel' => 'WEB']);

        $this->reset(['current_password', 'password', 'password_confirmation']);
        $this->flash = 'Kata sandi diganti. Sesi lain dan semua token API telah dicabut.';
    }

    public function render(): View
    {
        return view('livewire.profile', ['email' => $this->user()->email]);
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
