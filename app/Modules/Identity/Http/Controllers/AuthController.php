<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Services\UserProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

final class AuthController extends Controller
{
    public function __construct(
        private readonly UserProvisioningService $provisioning,
        private readonly AuditLogger $audit,
    ) {}

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            'device_name' => ['sometimes', 'string', 'max:64'],
        ]);

        $user = $this->provisioning->register($data['name'], $data['email'], $data['password']);
        $token = $this->issueToken($user, $data['device_name'] ?? 'api');

        return response()->json(['data' => ['user' => $this->userPayload($user), 'token' => $token, 'token_type' => 'Bearer']], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:64'],
        ]);

        /** @var User|null $user */
        $user = User::query()->where('email', mb_strtolower(trim($data['email'])))->first();

        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            $this->audit->record('auth.login_failed', $user, null, []);

            // Same message whether or not the email exists (no account enumeration).
            throw ValidationException::withMessages(['email' => [__('auth.failed')]]);
        }

        $token = $this->issueToken($user, $data['device_name'] ?? 'api');
        $this->audit->record('auth.login', $user, $user, ['device_name' => $data['device_name'] ?? 'api']);

        return response()->json(['data' => ['user' => $this->userPayload($user), 'token' => $token, 'token_type' => 'Bearer']]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();
        $this->audit->record('auth.logout', $request->user(), $request->user());

        return response()->json(['data' => ['message' => 'Logged out.']]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->userPayload($request->user())]);
    }

    private function issueToken(User $user, string $device): string
    {
        $minutes = (int) config('sanctum.expiration');

        return $user->createToken($device, ['*'], $minutes > 0 ? now()->addMinutes($minutes) : null)->plainTextToken;
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return ['id' => $user->public_id, 'name' => $user->name, 'email' => $user->email];
    }
}
