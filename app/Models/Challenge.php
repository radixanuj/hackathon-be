<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Challenge extends Model
{
    public const CATEGORIES = ['running', 'reading', 'photography', 'sports', 'learning', 'other'];

    protected $fillable = [
        'created_by', 'interest_group_id', 'title', 'slug', 'description', 'category',
        'unit', 'goal_value', 'starts_on', 'ends_on', 'status', 'participants_count',
    ];

    protected $attributes = ['status' => 'open', 'participants_count' => 0];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(InterestGroup::class, 'interest_group_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ChallengeParticipant::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'open')->whereDate('ends_on', '>=', now());
    }

    public function syncParticipantsCount(): void
    {
        $this->update(['participants_count' => $this->participants()->count()]);
    }

    public function isRunning(): bool
    {
        return $this->status === 'open'
            && now()->betweenIncluded($this->starts_on->startOfDay(), $this->ends_on->endOfDay());
    }
}
