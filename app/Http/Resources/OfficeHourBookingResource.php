<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OfficeHourBookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'office_hour_slot_id' => $this->office_hour_slot_id,
            'topic' => $this->topic,
            'status' => $this->status,
            'user' => new UserSummaryResource($this->whenLoaded('user')),
            'slot' => new OfficeHourSlotResource($this->whenLoaded('slot')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
