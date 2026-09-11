<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $tags = $this->whenLoaded('tags');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'job_title' => $this->job_title,
            'team' => $this->team,
            'location' => $this->location,
            'timezone' => $this->timezone,
            'joined_at' => $this->joined_at?->toDateString(),
            'tenure_years' => $this->tenureYears(),
            'tenure_band' => $this->tenureBand(),
            'is_new_joiner' => $this->isNewJoiner(),
            'intro' => $this->intro,
            // What they're into this month — reading, training for, watching.
            'currently' => $this->currentlyEntries(),
            'avatar_url' => $this->avatar_url,
            'pronouns' => $this->pronouns,
            'role' => $this->role,
            'is_active' => $this->is_active,
            'open_to_mentoring' => $this->open_to_mentoring,
            'open_to_blind_meetups' => $this->open_to_blind_meetups,
            'is_mentor' => $this->is_mentor,
            'can_talk_about' => $this->when($tags !== null, fn () => $this->tagNames('can_talk_about')),
            'can_help_with' => $this->when($tags !== null, fn () => $this->tagNames('can_help_with')),
            'want_to_learn' => $this->when($tags !== null, fn () => $this->tagNames('want_to_learn')),
            'interests' => $this->when($tags !== null, fn () => $this->tagNames('interest')),
            // Only on the single-profile endpoint, which is the only place
            // that can afford the extra lookup per person.
            'nudge' => $this->when($this->nudge_state !== null, fn () => $this->nudge_state),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** Pull one kind of tag out of the already-loaded relation, no extra queries. */
    protected function tagNames(string $kind): array
    {
        return $this->tags
            ->where('pivot.kind', $kind)
            ->map(fn ($tag) => ['id' => $tag->id, 'name' => $tag->name, 'slug' => $tag->slug])
            ->values()
            ->all();
    }
}
