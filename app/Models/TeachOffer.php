<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeachOffer extends Model
{
    public const FORMATS = ['session', 'workshop', 'walkthrough'];

    public const LEVELS = ['any', 'beginner', 'intermediate', 'advanced'];

    protected $fillable = [
        'user_id', 'tag_id', 'title', 'description', 'format', 'level', 'duration_minutes',
        'min_interested', 'preferred_times', 'status', 'scheduled_at', 'location', 'link',
        'event_id', 'interested_count',
    ];

    protected $attributes = ['status' => 'open', 'interested_count' => 0];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function interests(): HasMany
    {
        return $this->hasMany(TeachOfferInterest::class);
    }

    public function syncInterestedCount(): void
    {
        $this->update(['interested_count' => $this->interests()->count()]);
    }

    /** Enough people want it to be worth putting a date on. */
    public function hasEnoughInterest(): bool
    {
        return $this->interested_count >= $this->min_interested;
    }
}
