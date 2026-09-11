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
     * The roster the sign-in screen's name box searches.
     *
     * Unauthenticated by necessity — it is read before anyone has a token. It is
     * therefore deliberately thin: a name and where they sit, enough to tell two
     * people with the same first name apart, and no email or contact details.
     * Off entirely when demo sign-in is disabled.
     */
    public function directory(Request $request): JsonResponse
    {
        if (! config('radix.demo_login.enabled')) {
            return response()->json(['message' => 'Demo sign-in is disabled.'], 404);
        }

        $term = trim((string) $request->query('q', ''));

        $people = User::query()
            ->active()
            ->when($term !== '', fn ($q) => $q->where('name', 'like', '%'.$term.'%'))
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'job_title', 'team', 'location']);

        return response()->json(['data' => $people]);
    }

    /**
     * Demo sign-in: a person plus the shared password, no per-user credentials.
     *
     * The sign-in screen picks someone out of the directory above and sends their
     * `user_id`, so "Sahar Khan" and "Saif Khan" can never be confused for one
     * another. A bare `name` is still accepted — that is the path that creates a
     * profile on the spot for someone the roster has never heard of.
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
            'user_id' => ['required_without:name', 'nullable', 'integer', 'exists:users,id'],
            'name' => ['required_without:user_id', 'nullable', 'string', 'max:120'],
            'password' => ['required', 'string'],
        ]);

        if (! hash_equals((string) config('radix.demo_login.password'), $data['password'])) {
            throw ValidationException::withMessages([
                'password' => ['That password is not right.'],
            ]);
        }

        if (! empty($data['user_id'])) {
            return $this->tokenResponse($request, $this->assertActive(User::findOrFail($data['user_id'])));
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

        return $this->tokenResponse($request, $this->assertActive($user));
    }

    protected function assertActive(User $user): User
    {
        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'name' => ['This account is no longer active.'],
            ]);
        }

        return $user;
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
