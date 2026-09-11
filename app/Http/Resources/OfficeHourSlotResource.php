<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OfficeHourSlotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'duration_minutes' => $this->duration_minutes,
            'capacity' => $this->capacity,
            'bookings_count' => $this->bookings_count,
            'seats_left' => max(0, $this->capacity - $this->bookings_count),
            'is_full' => $this->isFull(),
            'location' => $this->location,
            'link' => $this->link,
            'status' => $this->status,
            'my_booking' => $this->when(isset($this->my_booking), fn () => $this->my_booking),
            'host' => new UserSummaryResource($this->whenLoaded('host')),
            'bookings' => OfficeHourBookingResource::collection($this->whenLoaded('bookings')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
