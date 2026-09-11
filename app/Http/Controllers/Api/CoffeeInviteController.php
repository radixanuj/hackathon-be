<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CoffeeInviteResource;
use App\Models\CoffeeInvite;
use App\Models\CoffeeInviteJoin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Open Coffee / Lunch Invites — Connect, Phase 2.
 *
 * Deliberately lighter than an Event: a time, a place and a couple of seats.
 */
class CoffeeInviteController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'kind' => ['nullable', Rule::in(CoffeeInvite::KINDS)],
            'location' => ['nullable', 'string'],
            'scope' => ['nullable', 'in:open,past,mine,all'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $userId = $request->user()->id;
        $scope = $filters['scope'] ?? 'open';

        $query = CoffeeInvite::query()
            ->with('host')
            ->when($filters['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->when($filters['location'] ?? null, fn ($q, $loc) => $q->where('location', $loc));

        match ($scope) {
            'open' => $query->open()->orderBy('starts_at'),
            'past' => $query->where('starts_at', '<', now())->orderByDesc('starts_at'),
            'mine' => $query->where(fn ($q) => $q->where('host_id', $userId)
                ->orWhereHas('joins', fn ($j) => $j->where('user_id', $userId)))
                ->orderByDesc('starts_at'),
            default => $query->orderByDesc('starts_at'),
        };

        $invites = $query->paginate($filters['per_page'] ?? 20)->withQueryString();
        $this->attachJoinState($invites, $userId);

        return CoffeeInviteResource::collection($invites);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(CoffeeInvite::KINDS)],
            'note' => ['nullable', 'string', 'max:500'],
            'starts_at' => ['required', 'date', 'after:now'],
            'location' => ['nullable', 'string', 'max:180'],
            'is_virtual' => ['nullable', 'boolean'],
            'link' => ['nullable', 'url', 'max:500'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $invite = $request->user()->coffeeInvites()->create($data);

        return response()->json([
            'data' => new CoffeeInviteResource($invite->load('host')),
        ], 201);
    }

    public function show(Request $request, CoffeeInvite $invite): JsonResponse
    {
        $invite->load(['host', 'joins.user']);
        $invite->has_joined = $invite->joins->contains('user_id', $request->user()->id);

        return response()->json(['data' => new CoffeeInviteResource($invite)]);
    }

    public function update(Request $request, CoffeeInvite $invite): JsonResponse
    {
        $this->assertHost($request, $invite);

        $data = $request->validate([
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'starts_at' => ['sometimes', 'date'],
            'location' => ['sometimes', 'nullable', 'string', 'max:180'],
            'is_virtual' => ['sometimes', 'boolean'],
            'link' => ['sometimes', 'nullable', 'url', 'max:500'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'status' => ['sometimes', 'in:open,cancelled'],
        ]);

        $invite->update($data);

        return response()->json(['data' => new CoffeeInviteResource($invite->fresh()->load('host'))]);
    }

    public function destroy(Request $request, CoffeeInvite $invite): JsonResponse
    {
        $this->assertHost($request, $invite);
        $invite->delete();

        return response()->json(['message' => 'Invite removed.']);
    }

    public function join(Request $request, CoffeeInvite $invite): JsonResponse
    {
        $user = $request->user();

        if ($invite->host_id === $user->id) {
            return response()->json(['message' => 'You are already hosting this one.'], 422);
        }

        if ($invite->status !== 'open' || $invite->starts_at->isPast()) {
            return response()->json(['message' => 'This invite is no longer open.'], 422);
        }

        if ($invite->isFull() && ! $invite->joins()->where('user_id', $user->id)->exists()) {
            return response()->json(['message' => 'All the seats are taken.'], 422);
        }

        $invite->joins()->firstOrCreate(['user_id' => $user->id]);
        $invite->syncJoinsCount();

        return $this->show($request, $invite->fresh());
    }

    public function leave(Request $request, CoffeeInvite $invite): JsonResponse
    {
        $invite->joins()->where('user_id', $request->user()->id)->delete();
        $invite->syncJoinsCount();

        return $this->show($request, $invite->fresh());
    }

    protected function assertHost(Request $request, CoffeeInvite $invite): void
    {
        if ($invite->host_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            throw new AccessDeniedHttpException('Only the host can manage this invite.');
        }
    }

    protected function attachJoinState($invites, int $userId): void
    {
        $joined = CoffeeInviteJoin::query()
            ->where('user_id', $userId)
            ->whereIn('coffee_invite_id', $invites->pluck('id'))
            ->pluck('coffee_invite_id')
            ->all();

        $invites->each(fn (CoffeeInvite $i) => $i->has_joined = in_array($i->id, $joined, true));
    }
}
