<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InterestGroup extends Model
{
    public const CATEGORIES = [
        'sports', 'books', 'tech', 'film', 'music', 'outdoors', 'games', 'food', 'other',
    ];

    protected $fillable = [
        'name', 'slug', 'description', 'category', 'emoji', 'cover_url',
        'external_platform', 'external_link', 'created_by', 'is_archived', 'members_count',
    ];

    protected function casts(): array
    {
        return ['is_archived' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected $attributes = ['members_count' => 0];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'interest_group_members')
            ->withPivot('role')->withTimestamps();
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function syncMembersCount(): void
    {
        $this->update(['members_count' => $this->members()->count()]);
    }
}
