<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetupSignup extends Model
{
    protected $fillable = ['meetup_round_id', 'user_id', 'status', 'note'];

    public function round(): BelongsTo
    {
        return $this->belongsTo(MeetupRound::class, 'meetup_round_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
