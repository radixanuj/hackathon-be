<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoffeeInviteJoin extends Model
{
    protected $fillable = ['coffee_invite_id', 'user_id'];

    public function invite(): BelongsTo
    {
        return $this->belongsTo(CoffeeInvite::class, 'coffee_invite_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
