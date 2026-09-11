<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChallengeLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'value' => $this->value,
            'note' => $this->note,
            'logged_on' => $this->logged_on?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
