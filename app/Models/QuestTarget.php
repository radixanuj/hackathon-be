<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestTarget extends Model
{
    protected $fillable = ['quest_id', 'target_user_id', 'reason', 'status', 'met_at', 'note'];

    protected function casts(): array
    {
        return ['met_at' => 'datetime'];
    }

    public function quest(): BelongsTo
    {
        return $this->belongsTo(Quest::class);
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
