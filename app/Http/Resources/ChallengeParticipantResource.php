<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChallengeParticipantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'challenge_id' => $this->challenge_id,
            'total_value' => $this->total_value,
            'rank' => $this->when(isset($this->rank), fn () => $this->rank),
            'user' => new UserSummaryResource($this->whenLoaded('user')),
            'logs' => ChallengeLogResource::collection($this->whenLoaded('logs')),
            'joined_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
