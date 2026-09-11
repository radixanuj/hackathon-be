<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuddyPairing extends Model
{
    protected $fillable = [
        'user_one_id', 'user_two_id', 'match_reason', 'status', 'started_at', 'ended_at',
    ];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function userOne(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_one_id');
    }

    public function userTwo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_two_id');
    }

    public function includes(int $userId): bool
    {
        return $this->user_one_id === $userId || $this->user_two_id === $userId;
    }

    public function partnerFor(int $userId): ?User
    {
        return $this->user_one_id === $userId ? $this->userTwo : $this->userOne;
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where(
            fn (Builder $q) => $q->where('user_one_id', $userId)->orWhere('user_two_id', $userId)
        );
    }
}
