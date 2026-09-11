<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SuggestionDismissal extends Model
{
    protected $fillable = ['user_id', 'dismissed_user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dismissedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_user_id');
    }
}
