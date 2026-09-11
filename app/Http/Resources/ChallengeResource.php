<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChallengeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'category' => $this->category,
            'unit' => $this->unit,
            'goal_value' => $this->goal_value,
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'status' => $this->status,
            'is_running' => $this->isRunning(),
            'days_left' => $this->ends_on ? max(0, (int) now()->startOfDay()->diffInDays($this->ends_on, false)) : null,
            'participants_count' => $this->participants_count,
            'my_participation' => new ChallengeParticipantResource($this->whenLoaded('myParticipation')),
            'creator' => new UserSummaryResource($this->whenLoaded('creator')),
            'group' => new InterestGroupResource($this->whenLoaded('group')),
            'leaderboard' => ChallengeParticipantResource::collection($this->whenLoaded('participants')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
