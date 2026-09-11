<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuestTargetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reason' => $this->reason,
            'status' => $this->status,
            'met_at' => $this->met_at?->toIso8601String(),
            'note' => $this->note,
            'person' => new UserSummaryResource($this->whenLoaded('targetUser')),
        ];
    }
}
