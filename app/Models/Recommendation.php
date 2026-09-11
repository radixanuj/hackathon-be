<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recommendation extends Model
{
    /**
     * What people actually recommend to each other.
     *
     * Wider than a media list because the Recommendation Corner on Find Your
     * Crowd is: alongside books and podcasts it carries newsletters, standing
     * resources and people worth following, and the "Recommend something" form
     * offers all of them.
     */
    public const TYPES = [
        'book', 'podcast', 'article', 'newsletter', 'show', 'film',
        'course', 'resource', 'tool', 'app', 'person', 'other',
    ];

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
