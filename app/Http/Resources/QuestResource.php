<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $targets = $this->whenLoaded('targets');

        return [
            'id' => $this->id,
            'status' => $this->status,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'due_at' => $this->due_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'days_remaining' => $this->due_at ? max(0, (int) now()->diffInDays($this->due_at, false)) : null,
            'met_count' => $targets ? $this->targets->where('status', 'met')->count() : null,
            'targets' => QuestTargetResource::collection($targets),
        ];
    }
}
