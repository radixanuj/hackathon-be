<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Http\Resources\EventRsvpResource;
use App\Models\Event;
use App\Models\EventRsvp;
use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Do Together: anyone creates an activity, Radix Connect does discovery + RSVPs. */
class EventController extends Controller
{
    public function __construct(protected Notifier $notifier) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', Rule::in(Event::CATEGORIES)],
            'scope' => ['nullable', Rule::in(['upcoming', 'past', 'all'])],
            'host_id' => ['nullable', 'integer', 'exists:users,id'],
            'group_id' => ['nullable', 'integer', 'exists:interest_groups,id'],
            'attending' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $userId = $request->user()->id;
        $scope = $filters['scope'] ?? 'upcoming';

        $query = Event::query()
            ->with(['host', 'group'])
            ->where('status', '!=', 'draft')
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(
                fn ($sub) => $sub->where('title', 'like', '%'.$term.'%')
                    ->orWhere('description', 'like', '%'.$term.'%')
                    ->orWhere('location', 'like', '%'.$term.'%')
            ))
            ->when($filters['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when($filters['host_id'] ?? null, fn ($q, $id) => $q->where('host_id', $id))
            ->when($filters['group_id'] ?? null, fn ($q, $id) => $q->where('interest_group_id', $id))
            ->when($request->boolean('attending'), fn ($q) => $q->whereHas(
                'rsvps', fn ($r) => $r->where('user_id', $userId)->where('status', 'going')
            ));

        match ($scope) {
            'upcoming' => $query->where('starts_at', '>=', now())->orderBy('starts_at'),
            'past' => $query->where('starts_at', '<', now())->orderByDesc('starts_at'),
            default => $query->orderByDesc('starts_at'),
        };

        $events = $query->paginate($filters['per_page'] ?? 20)->withQueryString();
        $this->attachRsvpState($events, $userId);

        return EventResource::collection($events);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:4000'],
            'category' => ['required', Rule::in(Event::CATEGORIES)],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'location' => ['nullable', 'string', 'max:180'],
            'is_virtual' => ['nullable', 'boolean'],
            'link' => ['nullable', 'url', 'max:500'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'interest_group_id' => ['nullable', 'integer', 'exists:interest_groups,id'],
            'status' => ['nullable', Rule::in(['draft', 'published'])],
        ]);

        $event = $request->user()->hostedEvents()->create($data + ['status' => $data['status'] ?? 'published']);

        // The host is going, by definition.
        $event->rsvps()->create(['user_id' => $request->user()->id, 'status' => 'going']);
        $event->syncGoingCount();

        // Deliberately no notification here. A new event is something people
        // find in the events list, not something that should land in every group
        // member's inbox. Only those who RSVP hear about it again — see update().

        return response()->json([
            'data' => new EventResource($event->fresh()->load(['host', 'group'])),
        ], 201);
    }

    public function show(Request $request, Event $event): JsonResponse
    {
        $event->load(['host', 'group', 'rsvps.user']);
        $event->my_rsvp = $event->rsvps->firstWhere('user_id', $request->user()->id)?->status;

        return response()->json(['data' => new EventResource($event)]);
    }

    public function update(Request $request, Event $event): JsonResponse
    {
        $this->assertHost($request, $event);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:180'],
            'description' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'category' => ['sometimes', Rule::in(Event::CATEGORIES)],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'location' => ['sometimes', 'nullable', 'string', 'max:180'],
            'is_virtual' => ['sometimes', 'boolean'],
            'link' => ['sometimes', 'nullable', 'url', 'max:500'],
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'status' => ['sometimes', Rule::in(['draft', 'published', 'cancelled', 'completed'])],
        ]);

        $movedTo = isset($data['starts_at']) && ! $event->starts_at->equalTo($data['starts_at'])
            ? $data['starts_at']
            : null;

        $event->update($data);
        $event->refresh();

        // Anyone who said they were coming has planned around this.
        if (($data['status'] ?? null) === 'cancelled') {
            $this->notifyAttendees($request, $event, 'event.cancelled', $event->title.' was cancelled', $this->eventWhen($event));
        } elseif ($movedTo) {
            $this->notifyAttendees($request, $event, 'event.updated', $event->title.' moved', 'Now '.$this->eventWhen($event));
        }

        return response()->json([
            'data' => new EventResource($event->fresh()->load(['host', 'group'])),
        ]);
    }

    public function destroy(Request $request, Event $event): JsonResponse
    {
        $this->assertHost($request, $event);
        $event->delete();

        return response()->json(['message' => 'Event removed.']);
    }

    public function rsvp(Request $request, Event $event): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(EventRsvp::STATUSES)],
        ]);

        if ($event->status === 'cancelled') {
            return response()->json(['message' => 'This event was cancelled.'], 422);
        }

        $existing = $event->rsvps()->where('user_id', $request->user()->id)->first();

        if ($data['status'] === 'going' && $event->isFull() && $existing?->status !== 'going') {
            return response()->json(['message' => 'This event is full.'], 422);
        }

        $event->rsvps()->updateOrCreate(['user_id' => $request->user()->id], ['status' => $data['status']]);
        $event->syncGoingCount();

        // Only a change of heart is worth a second notification.
        if ($existing?->status !== $data['status']) {
            $this->notifier->send($event->host_id, 'event.rsvp', [
                'actor' => $request->user(),
                'title' => $request->user()->name.' '.match ($data['status']) {
                    'going' => 'is coming to',
                    'maybe' => 'might come to',
                    'not_going' => 'cannot make',
                }.' '.$event->title,
                'body' => $event->going_count.' going so far.',
                'subject' => $event,
                'action_url' => '/community?tab=events',
            ]);
        }

        return $this->show($request, $event->fresh());
    }

    public function attendees(Request $request, Event $event): AnonymousResourceCollection
    {
        $rsvps = $event->rsvps()
            ->with('user')
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->paginate($request->integer('per_page', 50));

        return EventRsvpResource::collection($rsvps);
    }

    /** Everyone holding a going/maybe RSVP, minus whoever triggered the change. */
    protected function notifyAttendees(Request $request, Event $event, string $type, string $title, ?string $body): void
    {
        $this->notifier->sendMany(
            $event->rsvps()->whereIn('status', ['going', 'maybe'])->pluck('user_id'),
            $type,
            [
                'actor' => $request->user(),
                'title' => $title,
                'body' => $body,
                'subject' => $event,
                'action_url' => '/community?tab=events',
            ],
        );
    }

    protected function eventWhen(Event $event): string
    {
        return implode(' · ', array_filter([
            $event->starts_at?->format('D j M, g:ia'),
            $event->is_virtual ? 'Virtual' : $event->location,
        ]));
    }

    protected function assertHost(Request $request, Event $event): void
    {
        if ($event->host_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            throw new AccessDeniedHttpException('Only the host can manage this event.');
        }
    }

    protected function attachRsvpState($events, int $userId): void
    {
        $mine = EventRsvp::query()
            ->where('user_id', $userId)
            ->whereIn('event_id', $events->pluck('id'))
            ->pluck('status', 'event_id');

        $events->each(fn (Event $e) => $e->my_rsvp = $mine[$e->id] ?? null);
    }
}
