<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    public const CATEGORIES = [
        'outdoors', 'food', 'film', 'sports', 'games', 'culture', 'work', 'other',
    ];

    protected $fillable = [
        'host_id', 'interest_group_id', 'title', 'description', 'category', 'starts_at',
        'ends_at', 'location', 'is_virtual', 'link', 'capacity', 'status', 'going_count',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_virtual' => 'boolean',
        ];
    }

    protected $attributes = ['going_count' => 0];

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(InterestGroup::class, 'interest_group_id');
    }

    public function rsvps(): HasMany
    {
        return $this->hasMany(EventRsvp::class);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('starts_at', '>=', now())->where('status', 'published');
    }

    public function syncGoingCount(): void
    {
        $this->update(['going_count' => $this->rsvps()->where('status', 'going')->count()]);
    }

    public function isFull(): bool
    {
        return $this->capacity !== null && $this->going_count >= $this->capacity;
    }
}
