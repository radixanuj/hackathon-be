<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InterestGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'category' => $this->category,
            'emoji' => $this->emoji,
            'cover_url' => $this->cover_url,
            'external_platform' => $this->external_platform,
            'external_link' => $this->external_link,
            'is_archived' => $this->is_archived,
            'members_count' => $this->members_count,
            'is_member' => $this->when(isset($this->is_member), fn () => (bool) $this->is_member),
            'my_role' => $this->when(isset($this->my_role), fn () => $this->my_role),
            'creator' => new UserSummaryResource($this->whenLoaded('creator')),
            'members' => UserSummaryResource::collection($this->whenLoaded('members')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
