<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SessionRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewerId = $request->user()?->id;

        return [
            'id' => $this->id,
            'topic' => $this->topic,
            'category' => $this->category,
            'message' => $this->message,
            'duration_minutes' => $this->duration_minutes,
            'proposed_at' => $this->proposed_at?->toIso8601String(),
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'status' => $this->status,
            'response_message' => $this->response_message,
            'responded_at' => $this->responded_at?->toIso8601String(),
            'direction' => $viewerId === $this->requester_id ? 'outgoing' : 'incoming',
            'requester' => new UserSummaryResource($this->whenLoaded('requester')),
            'recipient' => new UserSummaryResource($this->whenLoaded('recipient')),
            'tag' => new TagResource($this->whenLoaded('tag')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
