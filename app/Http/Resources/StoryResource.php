<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'category' => $this->category,
            'media_url' => $this->media_url,
            'ama_id' => $this->ama_id,
            'reactions_count' => $this->reactions_count,
            'my_reaction' => $this->when(isset($this->my_reaction), fn () => $this->my_reaction),
            'user' => new UserSummaryResource($this->whenLoaded('user')),
            'ama' => new AmaResource($this->whenLoaded('ama')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
