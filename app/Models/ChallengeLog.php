<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChallengeLog extends Model
{
    protected $fillable = ['challenge_participant_id', 'value', 'note', 'logged_on'];

    protected function casts(): array
    {
        return ['logged_on' => 'date'];
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(ChallengeParticipant::class, 'challenge_participant_id');
    }
}
