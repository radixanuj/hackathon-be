<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChallengeParticipant extends Model
{
    protected $fillable = ['challenge_id', 'user_id', 'total_value'];

    protected $attributes = ['total_value' => 0];

    public function challenge(): BelongsTo
    {
        return $this->belongsTo(Challenge::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ChallengeLog::class);
    }

    public function syncTotal(): void
    {
        $this->update(['total_value' => (int) $this->logs()->sum('value')]);
    }
}
