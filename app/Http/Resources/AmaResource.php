<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AmaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'format' => $this->format,
            'status' => $this->status,
            'opens_at' => $this->opens_at?->toIso8601String(),
            'closes_at' => $this->closes_at?->toIso8601String(),
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'location' => $this->location,
            'story_id' => $this->story_id,
            'questions_count' => $this->questions_count,
            'host' => new UserSummaryResource($this->whenLoaded('host')),
            'questions' => AmaQuestionResource::collection($this->whenLoaded('questions')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
