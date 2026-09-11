<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OfficeHourBookingResource;
use App\Http\Resources\OfficeHourSlotResource;
use App\Models\OfficeHourBooking;
use App\Models\OfficeHourSlot;
use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Office Hours — Connect, Phase 2.
 *
 * A host publishes open slots and anyone books one. There is no accept/decline
 * step: that is what Mentoring session requests are for.
 */
class OfficeHourController extends Controller
{
    public function __construct(protected Notifier $notifier) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'host_id' => ['nullable', 'integer', 'exists:users,id'],
            'scope' => ['nullable', 'in:upcoming,past,all'],
            'available_only' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $scope = $filters['scope'] ?? 'upcoming';

        $query = OfficeHourSlot::query()
            ->with('host')
            ->where('status', 'open')
            ->when($filters['host_id'] ?? null, fn ($q, $id) => $q->where('host_id', $id))
            ->when($request->boolean('available_only'), fn ($q) => $q->whereColumn('bookings_count', '<', 'capacity'));

        match ($scope) {
            'upcoming' => $query->where('starts_at', '>=', now())->orderBy('starts_at'),
            'past' => $query->where('starts_at', '<', now())->orderByDesc('starts_at'),
            default => $query->orderBy('starts_at'),
        };

        $slots = $query->paginate($filters['per_page'] ?? 20)->withQueryString();
        $this->attachBookingState($slots, $request->user()->id);

        return OfficeHourSlotResource::collection($slots);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:140'],
            'description' => ['nullable', 'string', 'max:2000'],
            'starts_at' => ['required', 'date', 'after:now'],
            'duration_minutes' => ['nullable', 'integer', 'min:10', 'max:180'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:20'],
            'location' => ['nullable', 'string', 'max:180'],
            'link' => ['nullable', 'url', 'max:500'],
        ]);

        $slot = $request->user()->officeHourSlots()->create($data);

        return response()->json([
            'data' => new OfficeHourSlotResource($slot->load('host')),
        ], 201);
    }

    public function show(Request $request, OfficeHourSlot $slot): JsonResponse
    {
        $slot->load(['host', 'bookings.user']);
        $slot->my_booking = $slot->bookings->firstWhere('user_id', $request->user()->id)?->status;

        return response()->json(['data' => new OfficeHourSlotResource($slot)]);
    }

    public function update(Request $request, OfficeHourSlot $slot): JsonResponse
    {
        $this->assertHost($request, $slot);

        $data = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:140'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'starts_at' => ['sometimes', 'date'],
            'duration_minutes' => ['sometimes', 'integer', 'min:10', 'max:180'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'location' => ['sometimes', 'nullable', 'string', 'max:180'],
            'link' => ['sometimes', 'nullable', 'url', 'max:500'],
            'status' => ['sometimes', 'in:open,cancelled'],
        ]);

        $slot->update($data);

        if (($data['status'] ?? null) === 'cancelled') {
            $this->notifier->sendMany(
                $slot->bookings()->where('status', 'booked')->pluck('user_id'),
                'office_hours.slot_cancelled',
                [
                    'actor' => $request->user(),
                    'title' => $slot->host->name.' cancelled their office hours',
                    'body' => $this->slotLabel($slot),
                    'subject' => $slot,
                    'action_url' => '/connect?tab=office-hours',
                ],
            );
        }

        return response()->json(['data' => new OfficeHourSlotResource($slot->fresh()->load('host'))]);
    }

    public function destroy(Request $request, OfficeHourSlot $slot): JsonResponse
    {
        $this->assertHost($request, $slot);
        $slot->delete();

        return response()->json(['message' => 'Slot removed.']);
    }

    public function book(Request $request, OfficeHourSlot $slot): JsonResponse
    {
        $user = $request->user();

        if ($slot->host_id === $user->id) {
            return response()->json(['message' => 'You cannot book your own office hours.'], 422);
        }

        if ($slot->status !== 'open' || $slot->starts_at->isPast()) {
            return response()->json(['message' => 'This slot is no longer open.'], 422);
        }

        $existing = $slot->bookings()->where('user_id', $user->id)->first();

        if ($slot->isFull() && $existing?->status !== 'booked') {
            return response()->json(['message' => 'This slot is fully booked.'], 422);
        }

        $data = $request->validate(['topic' => ['nullable', 'string', 'max:180']]);

        $slot->bookings()->updateOrCreate(
            ['user_id' => $user->id],
            ['topic' => $data['topic'] ?? null, 'status' => 'booked'],
        );
        $slot->syncBookingsCount();

        $this->notifier->send($slot->host_id, 'office_hours.booked', [
            'actor' => $user,
            'title' => $user->name.' booked your office hours',
            'body' => $data['topic'] ?? $this->slotLabel($slot),
            'subject' => $slot,
            'action_url' => '/connect?tab=office-hours',
        ]);

        return $this->show($request, $slot->fresh());
    }

    public function cancelBooking(Request $request, OfficeHourSlot $slot): JsonResponse
    {
        $booking = $slot->bookings()->where('user_id', $request->user()->id)->first();

        if (! $booking) {
            return response()->json(['message' => 'You have not booked this slot.'], 404);
        }

        $booking->update(['status' => 'cancelled']);
        $slot->syncBookingsCount();

        $this->notifier->send($slot->host_id, 'office_hours.booking_cancelled', [
            'actor' => $request->user(),
            'title' => $request->user()->name.' cancelled their booking',
            'body' => $this->slotLabel($slot).' — that seat is free again.',
            'subject' => $slot,
            'action_url' => '/connect?tab=office-hours',
        ]);

        return $this->show($request, $slot->fresh());
    }

    /** Slots the signed-in user has booked. */
    public function myBookings(Request $request): AnonymousResourceCollection
    {
        $bookings = OfficeHourBooking::query()
            ->where('user_id', $request->user()->id)
            ->where('status', 'booked')
            ->with(['slot.host'])
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        return OfficeHourBookingResource::collection($bookings);
    }

    /** "Tuesday 3:00pm" — enough to recognise which slot is meant. */
    protected function slotLabel(OfficeHourSlot $slot): string
    {
        return trim(($slot->title ? $slot->title.' · ' : '').$slot->starts_at?->format('D j M, g:ia'));
    }

    protected function assertHost(Request $request, OfficeHourSlot $slot): void
    {
        if ($slot->host_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            throw new AccessDeniedHttpException('Only the host can manage this slot.');
        }
    }

    protected function attachBookingState($slots, int $userId): void
    {
        $mine = OfficeHourBooking::query()
            ->where('user_id', $userId)
            ->whereIn('office_hour_slot_id', $slots->pluck('id'))
            ->pluck('status', 'office_hour_slot_id');

        $slots->each(fn (OfficeHourSlot $s) => $s->my_booking = $mine[$s->id] ?? null);
    }
}
