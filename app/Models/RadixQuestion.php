<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Ask Radix. Named to keep it clear of AmaQuestion, which is a different thing. */
class RadixQuestion extends Model
{
    protected $fillable = [
        'user_id', 'title', 'body', 'status', 'answers_count',
        'volunteers_count', 'accepted_answer_id', 'resolved_at',
    ];

    protected $attributes = ['status' => 'open', 'answers_count' => 0, 'volunteers_count' => 0];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'radix_question_tag')->withTimestamps();
    }

    public function answers(): HasMany
    {
        return $this->hasMany(RadixAnswer::class);
    }

    public function volunteers(): HasMany
    {
        return $this->hasMany(RadixVolunteer::class);
    }

    public function syncCounts(): void
    {
        $this->update([
            'answers_count' => $this->answers()->count(),
            'volunteers_count' => $this->volunteers()->count(),
        ]);
    }
}
