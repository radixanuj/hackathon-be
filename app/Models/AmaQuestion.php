<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AmaQuestion extends Model
{
    protected $fillable = ['ama_id', 'user_id', 'body', 'upvotes_count'];

    protected $attributes = ['upvotes_count' => 0];

    public function ama(): BelongsTo
    {
        return $this->belongsTo(Ama::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(AmaAnswer::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(AmaQuestionVote::class);
    }

    public function syncUpvotesCount(): void
    {
        $this->update(['upvotes_count' => $this->votes()->count()]);
    }
}
