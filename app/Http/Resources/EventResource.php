<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'location' => $this->location,
            'is_virtual' => $this->is_virtual,
            'link' => $this->link,
            'capacity' => $this->capacity,
            'status' => $this->status,
            'going_count' => $this->going_count,
            'spots_left' => $this->capacity ? max(0, $this->capacity - $this->going_count) : null,
            'is_full' => $this->isFull(),
            'my_rsvp' => $this->when(isset($this->my_rsvp), fn () => $this->my_rsvp),
            'host' => new UserSummaryResource($this->whenLoaded('host')),
            'group' => new InterestGroupResource($this->whenLoaded('group')),
            'attendees' => EventRsvpResource::collection($this->whenLoaded('rsvps')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
