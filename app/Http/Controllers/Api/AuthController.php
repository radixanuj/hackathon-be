<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\QuestBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request, QuestBuilder $questBuilder): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'team' => ['nullable', 'string', 'max:80'],
            'location' => ['nullable', 'string', 'max:80'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'joined_at' => ['nullable', 'date'],
        ]);

        $user = User::create($data + ['joined_at' => $data['joined_at'] ?? now()->toDateString()]);

        // Everyone who joins gets their five people to meet.
        $questBuilder->buildFor($user);

        return $this->tokenResponse($request, $user, 201);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! auth()->getProvider()->validateCredentials($user, $credentials)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['This account is no longer active.'],
            ]);
        }

        return $this->tokenResponse($request, $user);
    }

    /**
     * Demo sign-in: a name plus the shared password, no per-user credentials.
     *
     * It returns the same token shape as a real login, so nothing downstream
     * knows the difference. Disable with DEMO_LOGIN_ENABLED=false.
     */
    public function demoLogin(Request $request, QuestBuilder $questBuilder): JsonResponse
    {
        if (! config('radix.demo_login.enabled')) {
            return response()->json(['message' => 'Demo sign-in is disabled.'], 404);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string'],
        ]);

        if (! hash_equals((string) config('radix.demo_login.password'), $data['password'])) {
            throw ValidationException::withMessages([
                'password' => ['That password is not right.'],
            ]);
        }

        $name = trim($data['name']);
        $user = User::whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();

        if (! $user) {
            if (! config('radix.demo_login.auto_create')) {
                throw ValidationException::withMessages([
                    'name' => ['We could not find anyone at Radix by that name.'],
                ]);
            }

            $user = User::create([
                'name' => $name,
                'email' => $this->demoEmailFor($name),
                'password' => config('radix.demo_login.password'),
                'joined_at' => now()->toDateString(),
            ]);

            $questBuilder->buildFor($user);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'name' => ['This account is no longer active.'],
            ]);
        }

        return $this->tokenResponse($request, $user);
    }

    /** A stable, unique placeholder address for demo-created profiles. */
    protected function demoEmailFor(string $name): string
    {
        $base = Str::slug($name) ?: 'employee';
        $email = $base.'@radix.email';
        $suffix = 2;

        while (User::where('email', $email)->exists()) {
            $email = $base.'-'.$suffix++.'@radix.email';
        }

        return $email;
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('tags'));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    /** Every successful response wraps its payload in `data`, auth included. */
    protected function tokenResponse(Request $request, User $user, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => [
                'token' => $user->createToken('radix-connect')->plainTextToken,
                'user' => (new UserResource($user->load('tags')))->resolve($request),
            ],
        ], $status);
    }
}
