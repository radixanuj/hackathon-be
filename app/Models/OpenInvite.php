<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OpenInvite extends Model
{
    protected $fillable = [
        'user_id', 'title', 'description', 'category', 'rough_timing',
        'location', 'status', 'event_id', 'interested_count',
    ];

    protected $attributes = ['status' => 'open', 'interested_count' => 0];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function interests(): HasMany
    {
        return $this->hasMany(OpenInviteInterest::class);
    }

    public function syncInterestedCount(): void
    {
        $this->update(['interested_count' => $this->interests()->count()]);
    }
}
