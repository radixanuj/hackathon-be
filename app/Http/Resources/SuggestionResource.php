<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One "Who Should I Meet?" suggestion — the person plus why they came up. */
class SuggestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'person' => new UserSummaryResource($this->resource['user']),
            'reasons' => $this->resource['reasons'],
            'previously_connected' => $this->resource['previously_connected'],
        ];
    }
}
