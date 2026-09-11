<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InterestGroupResource;
use App\Http\Resources\UserSummaryResource;
use App\Models\InterestGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Interest Groups. Radix Connect handles discovery only - the actual community
 * carries on in WhatsApp, Slack or wherever it already lives.
 */
class InterestGroupController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', Rule::in(InterestGroup::CATEGORIES)],
            'mine' => ['nullable', 'boolean'],
            'include_archived' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $userId = $request->user()->id;

        $query = InterestGroup::query()
            ->with('creator')
            ->when(! $request->boolean('include_archived'), fn ($q) => $q->where('is_archived', false))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(
                fn ($sub) => $sub->where('name', 'like', '%'.$term.'%')
                    ->orWhere('description', 'like', '%'.$term.'%')
            ))
            ->when($filters['category'] ?? null, fn ($q, $category) => $q->where('category', $category))
            ->when($request->boolean('mine'), fn ($q) => $q->whereHas(
                'members', fn ($m) => $m->where('users.id', $userId)
            ))
            ->orderByDesc('members_count')
            ->orderBy('name');

        $groups = $query->paginate($filters['per_page'] ?? 20)->withQueryString();
        $groups->each(fn (InterestGroup $g) => $this->attachMembership($g, $userId));

        return InterestGroupResource::collection($groups);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('interest_groups', 'name')],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['nullable', Rule::in(InterestGroup::CATEGORIES)],
            'emoji' => ['nullable', 'string', 'max:16'],
            'cover_url' => ['nullable', 'url', 'max:500'],
            'external_platform' => ['nullable', Rule::in(['whatsapp', 'slack', 'teams', 'discord', 'other'])],
            'external_link' => ['nullable', 'url', 'max:500'],
        ]);

        $group = InterestGroup::create($data + [
            'slug' => Str::slug($data['name']),
            'created_by' => $request->user()->id,
            'category' => $data['category'] ?? 'other',
        ]);

        // The creator is the first member and owns the group.
        $group->members()->attach($request->user()->id, ['role' => 'owner']);
        $group->syncMembersCount();

        return response()->json([
            'data' => new InterestGroupResource($this->attachMembership($group->fresh()->load('creator'), $request->user()->id)),
        ], 201);
    }

    public function show(Request $request, InterestGroup $group): JsonResponse
    {
        $group->load(['creator', 'members']);

        return response()->json([
            'data' => new InterestGroupResource($this->attachMembership($group, $request->user()->id)),
        ]);
    }

    public function update(Request $request, InterestGroup $group): JsonResponse
    {
        $this->assertOwner($request, $group);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80', Rule::unique('interest_groups', 'name')->ignore($group->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'category' => ['sometimes', Rule::in(InterestGroup::CATEGORIES)],
            'emoji' => ['sometimes', 'nullable', 'string', 'max:16'],
            'cover_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'external_platform' => ['sometimes', 'nullable', Rule::in(['whatsapp', 'slack', 'teams', 'discord', 'other'])],
            'external_link' => ['sometimes', 'nullable', 'url', 'max:500'],
            'is_archived' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['name'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        $group->update($data);

        return response()->json([
            'data' => new InterestGroupResource($this->attachMembership($group->fresh()->load('creator'), $request->user()->id)),
        ]);
    }

    public function destroy(Request $request, InterestGroup $group): JsonResponse
    {
        $this->assertOwner($request, $group);
        $group->delete();

        return response()->json(['message' => 'Group deleted.']);
    }

    public function join(Request $request, InterestGroup $group): JsonResponse
    {
        if ($group->is_archived) {
            return response()->json(['message' => 'This group is archived.'], 422);
        }

        $group->members()->syncWithoutDetaching([$request->user()->id => ['role' => 'member']]);
        $group->syncMembersCount();

        return response()->json([
            'data' => new InterestGroupResource($this->attachMembership($group->fresh()->load('creator'), $request->user()->id)),
        ]);
    }

    public function leave(Request $request, InterestGroup $group): JsonResponse
    {
        $group->members()->detach($request->user()->id);
        $group->syncMembersCount();

        return response()->json([
            'data' => new InterestGroupResource($this->attachMembership($group->fresh()->load('creator'), $request->user()->id)),
        ]);
    }

    public function members(Request $request, InterestGroup $group): AnonymousResourceCollection
    {
        return UserSummaryResource::collection(
            $group->members()->orderBy('name')->paginate($request->integer('per_page', 50))
        );
    }

    protected function assertOwner(Request $request, InterestGroup $group): void
    {
        $user = $request->user();
        $isOwner = $group->created_by === $user->id
            || $group->members()->where('users.id', $user->id)->wherePivot('role', 'owner')->exists();

        if (! $isOwner && ! $user->isAdmin()) {
            throw new AccessDeniedHttpException('Only the group owner can do this.');
        }
    }

    /** Flag whether the viewer is in the group, so the FE can render Join vs Leave. */
    protected function attachMembership(InterestGroup $group, int $userId): InterestGroup
    {
        $membership = $group->members()->where('users.id', $userId)->first();
        $group->is_member = $membership !== null;
        $group->my_role = $membership?->pivot->role;

        return $group;
    }
}
