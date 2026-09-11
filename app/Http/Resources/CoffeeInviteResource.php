<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CoffeeInviteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'note' => $this->note,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'location' => $this->location,
            'is_virtual' => $this->is_virtual,
            'link' => $this->link,
            'capacity' => $this->capacity,
            'joins_count' => $this->joins_count,
            'seats_left' => max(0, $this->capacity - $this->joins_count),
            'is_full' => $this->isFull(),
            'status' => $this->status,
            'has_joined' => $this->when(isset($this->has_joined), fn () => (bool) $this->has_joined),
            'host' => new UserSummaryResource($this->whenLoaded('host')),
            'guests' => UserSummaryResource::collection(
                $this->whenLoaded('joins', fn () => $this->joins->pluck('user')->filter())
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
