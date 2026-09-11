<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BuddyPairingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewerId = $request->user()?->id;
        $partner = $viewerId && $this->includes($viewerId) ? $this->partnerFor($viewerId) : null;

        return [
            'id' => $this->id,
            'status' => $this->status,
            'match_reason' => $this->match_reason,
            'started_at' => $this->started_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'buddy' => $partner ? new UserSummaryResource($partner) : null,
            'participants' => $this->when($partner === null, fn () => [
                new UserSummaryResource($this->userOne),
                new UserSummaryResource($this->userTwo),
            ]),
        ];
    }
}
