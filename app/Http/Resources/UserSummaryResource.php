<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The compact user shape embedded everywhere a person is referenced. */
class UserSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'job_title' => $this->job_title,
            'team' => $this->team,
            'location' => $this->location,
            'timezone' => $this->timezone,
            'avatar_url' => $this->avatar_url,
            'pronouns' => $this->pronouns,
            'tenure_years' => $this->tenureYears(),
            'tenure_band' => $this->tenureBand(),
        ];
    }
}
