<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CoffeeInvite extends Model
{
    public const KINDS = ['coffee', 'lunch', 'walk'];

    protected $fillable = [
        'host_id', 'kind', 'note', 'starts_at', 'location', 'is_virtual',
        'link', 'capacity', 'status', 'joins_count',
    ];

    protected $attributes = ['status' => 'open', 'joins_count' => 0, 'capacity' => 3];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'is_virtual' => 'boolean'];
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    public function joins(): HasMany
    {
        return $this->hasMany(CoffeeInviteJoin::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open')->where('starts_at', '>=', now());
    }

    public function syncJoinsCount(): void
    {
        $this->update(['joins_count' => $this->joins()->count()]);
    }

    /** The host does not occupy one of the seats on offer. */
    public function isFull(): bool
    {
        return $this->joins_count >= $this->capacity;
    }
}
