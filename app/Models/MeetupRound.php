<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeetupRound extends Model
{
    protected $fillable = [
        'title', 'period', 'signups_open_at', 'signups_close_at', 'meetup_date', 'status',
    ];

    protected function casts(): array
    {
        return [
            'signups_open_at' => 'datetime',
            'signups_close_at' => 'datetime',
            'meetup_date' => 'date',
        ];
    }

    public function signups(): HasMany
    {
        return $this->hasMany(MeetupSignup::class);
    }

    public function pairs(): HasMany
    {
        return $this->hasMany(MeetupPair::class);
    }

    public function isAcceptingSignups(): bool
    {
        return $this->status === 'open'
            && now()->between($this->signups_open_at, $this->signups_close_at);
    }
}
