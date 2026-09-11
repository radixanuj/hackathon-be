<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeachOfferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'format' => $this->format,
            'level' => $this->level,
            'duration_minutes' => $this->duration_minutes,
            'min_interested' => $this->min_interested,
            'interested_count' => $this->interested_count,
            'has_enough_interest' => $this->hasEnoughInterest(),
            'preferred_times' => $this->preferred_times,
            'status' => $this->status,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'location' => $this->location,
            'link' => $this->link,
            'event_id' => $this->event_id,
            'im_interested' => $this->when(isset($this->im_interested), fn () => (bool) $this->im_interested),
            'user' => new UserSummaryResource($this->whenLoaded('user')),
            'tag' => new TagResource($this->whenLoaded('tag')),
            'interested_people' => UserSummaryResource::collection(
                $this->whenLoaded('interests', fn () => $this->interests->pluck('user')->filter())
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
