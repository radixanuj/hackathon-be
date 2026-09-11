<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Story extends Model
{
    public const CATEGORIES = ['sport', 'travel', 'learning', 'making', 'milestone', 'other'];

    protected $fillable = [
        'user_id', 'title', 'body', 'category', 'media_url', 'ama_id', 'reactions_count',
    ];

    protected $attributes = ['reactions_count' => 0];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ama(): BelongsTo
    {
        return $this->belongsTo(Ama::class);
    }

    /** Phase 2: tags make a story discoverable through interests and profiles. */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'story_tag')->withTimestamps();
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(StoryReaction::class);
    }

    public function syncReactionsCount(): void
    {
        $this->update(['reactions_count' => $this->reactions()->count()]);
    }
}
