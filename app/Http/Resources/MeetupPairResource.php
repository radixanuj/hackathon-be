<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeetupPairResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewerId = $request->user()?->id;
        $partner = $viewerId && $this->includes($viewerId) ? $this->partnerFor($viewerId) : null;

        return [
            'id' => $this->id,
            'meetup_round_id' => $this->meetup_round_id,
            'status' => $this->status,
            'match_reason' => $this->match_reason,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'round' => new MeetupRoundResource($this->whenLoaded('round')),
            // From a participant's point of view the only person that matters is the other one.
            'partner' => $partner ? new UserSummaryResource($partner) : null,
            'participants' => $this->when($partner === null, fn () => [
                new UserSummaryResource($this->userOne),
                new UserSummaryResource($this->userTwo),
            ]),
        ];
    }
}
