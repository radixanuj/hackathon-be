<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpenInviteInterest extends Model
{
    protected $fillable = ['open_invite_id', 'user_id'];

    public function invite(): BelongsTo
    {
        return $this->belongsTo(OpenInvite::class, 'open_invite_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
