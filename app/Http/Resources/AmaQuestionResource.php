<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AmaQuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ama_id' => $this->ama_id,
            'body' => $this->body,
            'upvotes_count' => $this->upvotes_count,
            'is_upvoted' => $this->when(isset($this->is_upvoted), fn () => (bool) $this->is_upvoted),
            'user' => new UserSummaryResource($this->whenLoaded('user')),
            'answers' => AmaAnswerResource::collection($this->whenLoaded('answers')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
