<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Http\Resources\OpenInviteResource;
use App\Models\Event;
use App\Models\OpenInvite;
use App\Models\OpenInviteInterest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Open Invites — Do Together, Phase 2.
 *
 * "Anyone interested in a trek sometime?" with no date and no logistics. It
 * becomes a real Event only once enough people have said yes.
 */
class OpenInviteController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', Rule::in(Event::CATEGORIES)],
            'status' => ['nullable', 'in:open,converted,closed'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'mine' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $userId = $request->user()->id;

        $invites = OpenInvite::query()
            ->with(['user', 'event'])
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(
                fn ($sub) => $sub->where('title', 'like', '%'.$term.'%')
                    ->orWhere('description', 'like', '%'.$term.'%')
            ))
            ->when($filters['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s),
                fn ($q) => $q->where('status', 'open'))
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($request->boolean('mine'), fn ($q) => $q->where(
                fn ($sub) => $sub->where('user_id', $userId)
                    ->orWhereHas('interests', fn ($i) => $i->where('user_id', $userId))
            ))
            ->orderByDesc('interested_count')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        $this->attachInterestState($invites, $userId);

        return OpenInviteResource::collection($invites);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['required', Rule::in(Event::CATEGORIES)],
            'rough_timing' => ['nullable', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:180'],
        ]);

        $invite = $request->user()->openInvites()->create($data);

        // Floating the idea counts as being interested in it.
        $invite->interests()->create(['user_id' => $request->user()->id]);
        $invite->syncInterestedCount();

        return response()->json([
            'data' => new OpenInviteResource($invite->fresh()->load('user')),
        ], 201);
    }

    public function show(Request $request, OpenInvite $invite): JsonResponse
    {
        $invite->load(['user', 'event', 'interests.user']);
        $invite->im_interested = $invite->interests->contains('user_id', $request->user()->id);

        return response()->json(['data' => new OpenInviteResource($invite)]);
    }

    public function update(Request $request, OpenInvite $invite): JsonResponse
    {
        $this->assertOwner($request, $invite);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:180'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'category' => ['sometimes', Rule::in(Event::CATEGORIES)],
            'rough_timing' => ['sometimes', 'nullable', 'string', 'max:120'],
            'location' => ['sometimes', 'nullable', 'string', 'max:180'],
            'status' => ['sometimes', 'in:open,closed'],
        ]);

        $invite->update($data);

        return response()->json(['data' => new OpenInviteResource($invite->fresh()->load('user'))]);
    }

    public function destroy(Request $request, OpenInvite $invite): JsonResponse
    {
        $this->assertOwner($request, $invite);
        $invite->delete();

        return response()->json(['message' => 'Open invite removed.']);
    }

    public function express(Request $request, OpenInvite $invite): JsonResponse
    {
        if ($invite->status !== 'open') {
            return response()->json(['message' => 'This invite is no longer open.'], 422);
        }

        $invite->interests()->firstOrCreate(['user_id' => $request->user()->id]);
        $invite->syncInterestedCount();

        return $this->show($request, $invite->fresh());
    }

    public function withdraw(Request $request, OpenInvite $invite): JsonResponse
    {
        $invite->interests()->where('user_id', $request->user()->id)->delete();
        $invite->syncInterestedCount();

        return $this->show($request, $invite->fresh());
    }

    /** Enough interest — turn it into a real Event, RSVPing everyone who said yes. */
    public function convertToEvent(Request $request, OpenInvite $invite): JsonResponse
    {
        $this->assertOwner($request, $invite);

        if ($invite->event_id) {
            return response()->json(['message' => 'This invite already became an event.'], 422);
        }

        $data = $request->validate([
            'starts_at' => ['required', 'date', 'after:now'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'location' => ['nullable', 'string', 'max:180'],
            'is_virtual' => ['nullable', 'boolean'],
            'link' => ['nullable', 'url', 'max:500'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $event = Event::create($data + [
            'host_id' => $invite->user_id,
            'title' => $invite->title,
            'description' => $invite->description,
            'category' => $invite->category,
            'location' => $data['location'] ?? $invite->location,
        ]);

        foreach ($invite->interests()->pluck('user_id') as $userId) {
            $event->rsvps()->firstOrCreate(['user_id' => $userId], ['status' => 'going']);
        }

        $event->rsvps()->firstOrCreate(['user_id' => $invite->user_id], ['status' => 'going']);
        $event->syncGoingCount();

        $invite->update(['status' => 'converted', 'event_id' => $event->id]);

        return response()->json([
            'message' => 'Event created — everyone interested is RSVP\'d.',
            'data' => new EventResource($event->fresh()->load('host')),
        ], 201);
    }

    protected function assertOwner(Request $request, OpenInvite $invite): void
    {
        if ($invite->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            throw new AccessDeniedHttpException('This invite is not yours.');
        }
    }

    protected function attachInterestState($invites, int $userId): void
    {
        $mine = OpenInviteInterest::query()
            ->where('user_id', $userId)
            ->whereIn('open_invite_id', $invites->pluck('id'))
            ->pluck('open_invite_id')
            ->all();

        $invites->each(fn (OpenInvite $i) => $i->im_interested = in_array($i->id, $mine, true));
    }
}
