<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OpenInviteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category,
            'rough_timing' => $this->rough_timing,
            'location' => $this->location,
            'status' => $this->status,
            'interested_count' => $this->interested_count,
            'event_id' => $this->event_id,
            'im_interested' => $this->when(isset($this->im_interested), fn () => (bool) $this->im_interested),
            'user' => new UserSummaryResource($this->whenLoaded('user')),
            'event' => new EventResource($this->whenLoaded('event')),
            'interested_people' => UserSummaryResource::collection(
                $this->whenLoaded('interests', fn () => $this->interests->pluck('user')->filter())
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
