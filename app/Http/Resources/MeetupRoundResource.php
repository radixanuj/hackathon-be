<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeetupRoundResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'period' => $this->period,
            'signups_open_at' => $this->signups_open_at?->toIso8601String(),
            'signups_close_at' => $this->signups_close_at?->toIso8601String(),
            'meetup_date' => $this->meetup_date?->toDateString(),
            'status' => $this->status,
            'is_accepting_signups' => $this->isAcceptingSignups(),
            'signups_count' => $this->whenCounted('signups'),
            'pairs_count' => $this->whenCounted('pairs'),
            'my_signup' => new MeetupSignupResource($this->whenLoaded('mySignup')),
            'my_pair' => new MeetupPairResource($this->whenLoaded('myPair')),
        ];
    }
}
