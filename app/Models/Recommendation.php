<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recommendation extends Model
{
    public const TYPES = ['book', 'podcast', 'article', 'show', 'film', 'course', 'tool'];

    public const STREAMS = ['work', 'leisure'];

    protected $fillable = [
        'user_id', 'title', 'creator', 'type', 'stream', 'url', 'why', 'likes_count',
    ];

    protected $attributes = ['likes_count' => 0];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function likes(): HasMany
    {
        return $this->hasMany(RecommendationLike::class);
    }

    public function syncLikesCount(): void
    {
        $this->update(['likes_count' => $this->likes()->count()]);
    }
}
