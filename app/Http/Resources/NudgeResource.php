<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A nudge, told from the point of view of whoever is asking.
 *
 * Both sides of an exchange read the same row, so `person` is always the *other*
 * one and `direction` says which way it went. That keeps the client from having
 * to compare ids against the signed-in user on every row it draws.
 */
class NudgeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewerId = $request->user()?->id;
        $sent = $this->sender_id === $viewerId;

        return [
            'id' => $this->id,
            'direction' => $sent ? 'sent' : 'received',
            'person' => new UserSummaryResource($sent ? $this->whenLoaded('recipient') : $this->whenLoaded('sender')),
            'sender_id' => $this->sender_id,
            'recipient_id' => $this->recipient_id,
            'streak' => $this->streak,
            'is_outstanding' => $this->isOutstanding(),
            // Whose turn it is, which is the only thing the buttons care about.
            'waiting_on_you' => $this->isOutstanding() && ! $sent,
            'waiting_on_them' => $this->isOutstanding() && $sent,
            'returned_at' => $this->returned_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
