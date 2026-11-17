<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Models\User;
use App\Modules\Categories\Models\TransactionCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\Support\BuildsFinance;
use Tests\TestCase;

final class AccessControlTest extends TestCase
{
    use BuildsFinance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_registration_can_be_closed_from_env(): void
    {
        config(['access.registration_enabled' => false]);

        $this->get('/register')->assertNotFound();
        $this->get('/login')->assertOk()->assertDontSee('Belum punya akun?');
        $this->postJson('/api/v1/auth/register', [
            'name' => 'X', 'email' => 'x@example.test', 'password' => 'Rahasia-Kuat-123', 'password_confirmation' => 'Rahasia-Kuat-123',
        ])->assertNotFound();
        $this->assertSame(0, User::query()->count());
    }

    public function test_only_allowed_emails_can_register_and_sign_in(): void
    {
        config(['access.allowed_emails' => 'ani@example.test, BUDI@example.test']);
        $this->provisionedUser(['email' => 'eve@example.test', 'password' => 'Rahasia-Kuat-123']);
        $this->provisionedUser(['email' => 'ani@example.test', 'password' => 'Rahasia-Kuat-123']);

        Livewire::test(Login::class)->set('email', 'eve@example.test')->set('password', 'Rahasia-Kuat-123')
            ->call('login')->assertHasErrors(['email' => __('auth.failed')]);
        $this->assertGuest();

        $this->postJson('/api/v1/auth/login', ['email' => 'eve@example.test', 'password' => 'Rahasia-Kuat-123'])->assertStatus(422);

        Livewire::test(Login::class)->set('email', 'ani@example.test')->set('password', 'Rahasia-Kuat-123')
            ->call('login')->assertHasNoErrors()->assertRedirect(route('dashboard'));

        auth()->logout();
        Livewire::test(Register::class)->set('name', 'Eve 2')->set('email', 'eve2@example.test')
            ->set('password', 'Rahasia-Kuat-123')->set('password_confirmation', 'Rahasia-Kuat-123')
            ->call('register')->assertHasErrors('email');
        $this->assertFalse(User::query()->where('email', 'eve2@example.test')->exists());
    }

    public function test_removing_an_email_from_the_list_cuts_existing_sessions_and_tokens(): void
    {
        $user = $this->provisionedUser(['email' => 'ani@example.test']);
        $token = $user->createToken('cli')->plainTextToken;

        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

        config(['access.allowed_emails' => 'someone-else@example.test']);

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('login'));
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertForbidden();
    }

    public function test_dashboard_user_command_creates_and_updates_the_env_account(): void
    {
        config(['access.admin' => ['name' => 'Pemilik', 'email' => 'Owner@Example.test', 'password' => 'lemah']]);
        $this->artisan('dashboard:user')->assertFailed();
        $this->assertSame(0, User::query()->count());

        config(['access.admin.password' => 'Rahasia-Kuat-123']);
        $this->artisan('dashboard:user')->expectsOutputToContain('created')->assertSuccessful();

        $user = User::query()->where('email', 'owner@example.test')->sole();
        $this->assertSame('Pemilik', $user->name);
        $this->assertTrue(Hash::check('Rahasia-Kuat-123', $user->password));
        $this->assertTrue(TransactionCategory::query()->where('user_id', $user->id)->exists(), 'Provisioned like a normal sign-up.');

        // Running it again changes nothing.
        $this->artisan('dashboard:user')->expectsOutputToContain('up to date')->assertSuccessful();

        // A bcrypt hash in .env is stored as-is and revokes old tokens.
        $user->createToken('old');
        $hash = Hash::make('Baru-Lebih-Kuat-456');
        config(['access.admin.password' => $hash]);
        $this->artisan('dashboard:user')->expectsOutputToContain('Password updated')->assertSuccessful();

        $user->refresh();
        $this->assertSame($hash, $user->password);
        $this->assertTrue(Hash::check('Baru-Lebih-Kuat-456', $user->password));
        $this->assertSame(0, $user->tokens()->count());

        $this->post('/logout');
        Livewire::test(Login::class)->set('email', 'owner@example.test')->set('password', 'Baru-Lebih-Kuat-456')
            ->call('login')->assertHasNoErrors();
    }

    public function test_seeder_creates_no_default_account_without_env_settings(): void
    {
        config(['access.admin' => ['name' => 'Admin', 'email' => '', 'password' => '']]);
        $this->seed();
        $this->assertSame(0, User::query()->count());
    }
}
