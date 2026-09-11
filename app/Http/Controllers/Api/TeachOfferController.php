<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Http\Resources\TeachOfferResource;
use App\Models\Event;
use App\Models\Tag;
use App\Models\TeachOffer;
use App\Models\TeachOfferInterest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Teach Radix — Learn & Share, Phase 2.
 *
 * The mirror of Ask Radix: offer an informal session and find out whether anyone
 * wants it before committing to a date. Once enough people are interested the
 * offer can be turned into a real Event.
 */
class TeachOfferController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:open,scheduled,delivered,cancelled'],
            'level' => ['nullable', Rule::in(TeachOffer::LEVELS)],
            'format' => ['nullable', Rule::in(TeachOffer::FORMATS)],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'ready' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = TeachOffer::query()
            ->with(['user', 'tag'])
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(
                fn ($sub) => $sub->where('title', 'like', '%'.$term.'%')
                    ->orWhere('description', 'like', '%'.$term.'%')
            ))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['level'] ?? null, fn ($q, $l) => $q->where('level', $l))
            ->when($filters['format'] ?? null, fn ($q, $f) => $q->where('format', $f))
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            // Offers that have hit their interest threshold and just need a date.
            ->when($request->boolean('ready'), fn ($q) => $q->where('status', 'open')
                ->whereColumn('interested_count', '>=', 'min_interested'))
            ->orderByDesc('id');

        $offers = $query->paginate($filters['per_page'] ?? 20)->withQueryString();
        $this->attachInterestState($offers, $request->user()->id);

        return TeachOfferResource::collection($offers);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:4000'],
            'format' => ['required', Rule::in(TeachOffer::FORMATS)],
            'level' => ['nullable', Rule::in(TeachOffer::LEVELS)],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:240'],
            'min_interested' => ['nullable', 'integer', 'min:1', 'max:100'],
            'preferred_times' => ['nullable', 'string', 'max:180'],
            'topic' => ['nullable', 'string', 'max:60'],
        ]);

        $tagId = ! empty($data['topic']) ? Tag::findOrCreateByName($data['topic'], 'skill')->id : null;

        $offer = $request->user()->teachOffers()->create(
            collect($data)->except('topic')->all() + ['tag_id' => $tagId]
        );

        return response()->json([
            'data' => new TeachOfferResource($offer->load(['user', 'tag'])),
        ], 201);
    }

    public function show(Request $request, TeachOffer $offer): JsonResponse
    {
        $offer->load(['user', 'tag', 'interests.user']);
        $offer->im_interested = $offer->interests->contains('user_id', $request->user()->id);

        return response()->json(['data' => new TeachOfferResource($offer)]);
    }

    public function update(Request $request, TeachOffer $offer): JsonResponse
    {
        $this->assertOwner($request, $offer);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:180'],
            'description' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'format' => ['sometimes', Rule::in(TeachOffer::FORMATS)],
            'level' => ['sometimes', Rule::in(TeachOffer::LEVELS)],
            'duration_minutes' => ['sometimes', 'integer', 'min:15', 'max:240'],
            'min_interested' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'preferred_times' => ['sometimes', 'nullable', 'string', 'max:180'],
            'status' => ['sometimes', 'in:open,scheduled,delivered,cancelled'],
            'scheduled_at' => ['sometimes', 'nullable', 'date'],
            'location' => ['sometimes', 'nullable', 'string', 'max:180'],
            'link' => ['sometimes', 'nullable', 'url', 'max:500'],
        ]);

        $offer->update($data);

        return response()->json([
            'data' => new TeachOfferResource($offer->fresh()->load(['user', 'tag'])),
        ]);
    }

    public function destroy(Request $request, TeachOffer $offer): JsonResponse
    {
        $this->assertOwner($request, $offer);
        $offer->delete();

        return response()->json(['message' => 'Offer removed.']);
    }

    public function express(Request $request, TeachOffer $offer): JsonResponse
    {
        if ($offer->user_id === $request->user()->id) {
            return response()->json(['message' => 'You are the one offering this.'], 422);
        }

        if (in_array($offer->status, ['delivered', 'cancelled'], true)) {
            return response()->json(['message' => 'This offer is no longer open.'], 422);
        }

        $offer->interests()->firstOrCreate(['user_id' => $request->user()->id]);
        $offer->syncInterestedCount();

        return $this->show($request, $offer->fresh());
    }

    public function withdraw(Request $request, TeachOffer $offer): JsonResponse
    {
        $offer->interests()->where('user_id', $request->user()->id)->delete();
        $offer->syncInterestedCount();

        return $this->show($request, $offer->fresh());
    }

    /** Enough people want it — put a date on it and everyone interested is RSVP'd. */
    public function schedule(Request $request, TeachOffer $offer): JsonResponse
    {
        $this->assertOwner($request, $offer);

        if ($offer->event_id) {
            return response()->json(['message' => 'This offer is already scheduled.'], 422);
        }

        $data = $request->validate([
            'starts_at' => ['required', 'date', 'after:now'],
            'location' => ['nullable', 'string', 'max:180'],
            'link' => ['nullable', 'url', 'max:500'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $event = Event::create([
            'host_id' => $offer->user_id,
            'title' => $offer->title,
            'description' => $offer->description,
            'category' => 'work',
            'starts_at' => $data['starts_at'],
            'ends_at' => Carbon::parse($data['starts_at'])
                ->addMinutes($offer->duration_minutes),
            'location' => $data['location'] ?? $offer->location,
            'is_virtual' => ! empty($data['link']),
            'link' => $data['link'] ?? $offer->link,
            'capacity' => $data['capacity'] ?? null,
        ]);

        $event->rsvps()->create(['user_id' => $offer->user_id, 'status' => 'going']);

        foreach ($offer->interests()->pluck('user_id') as $userId) {
            $event->rsvps()->firstOrCreate(['user_id' => $userId], ['status' => 'going']);
        }

        $event->syncGoingCount();

        $offer->update([
            'status' => 'scheduled',
            'scheduled_at' => $data['starts_at'],
            'location' => $data['location'] ?? $offer->location,
            'link' => $data['link'] ?? $offer->link,
            'event_id' => $event->id,
        ]);

        return response()->json([
            'message' => 'Scheduled — everyone who was interested is RSVP\'d.',
            'data' => new EventResource($event->fresh()->load('host')),
        ], 201);
    }

    protected function assertOwner(Request $request, TeachOffer $offer): void
    {
        if ($offer->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            throw new AccessDeniedHttpException('This offer is not yours.');
        }
    }

    protected function attachInterestState($offers, int $userId): void
    {
        $mine = TeachOfferInterest::query()
            ->where('user_id', $userId)
            ->whereIn('teach_offer_id', $offers->pluck('id'))
            ->pluck('teach_offer_id')
            ->all();

        $offers->each(fn (TeachOffer $o) => $o->im_interested = in_array($o->id, $mine, true));
    }
}
