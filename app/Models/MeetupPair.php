<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetupPair extends Model
{
    protected $fillable = [
        'meetup_round_id', 'user_one_id', 'user_two_id', 'match_reason', 'status', 'scheduled_at',
    ];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime'];
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(MeetupRound::class, 'meetup_round_id');
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

    /** The other half of the pair, from one participant's point of view. */
    public function partnerFor(int $userId): ?User
    {
        return $this->user_one_id === $userId ? $this->userTwo : $this->userOne;
    }
}
