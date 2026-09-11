<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RadixQuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'status' => $this->status,
            'answers_count' => $this->answers_count,
            'volunteers_count' => $this->volunteers_count,
            'accepted_answer_id' => $this->accepted_answer_id,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'i_volunteered' => $this->when(isset($this->i_volunteered), fn () => (bool) $this->i_volunteered),
            // Why this question reached you: it matches something on your profile.
            'matches_my_profile' => $this->when(isset($this->matches_my_profile), fn () => (bool) $this->matches_my_profile),
            'user' => new UserSummaryResource($this->whenLoaded('user')),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'answers' => RadixAnswerResource::collection($this->whenLoaded('answers')),
            'volunteers' => RadixVolunteerResource::collection($this->whenLoaded('volunteers')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
