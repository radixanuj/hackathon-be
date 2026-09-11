<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecommendationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'creator' => $this->creator,
            'type' => $this->type,
            'stream' => $this->stream,
            'url' => $this->url,
            'why' => $this->why,
            'likes_count' => $this->likes_count,
            'is_liked' => $this->when(isset($this->is_liked), fn () => (bool) $this->is_liked),
            'user' => new UserSummaryResource($this->whenLoaded('user')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
