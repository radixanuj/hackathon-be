<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SessionRequest extends Model
{
    public const CATEGORIES = [
        'work_knowledge', 'career', 'leadership', 'people', 'technical', 'personal_experience',
    ];

    protected $fillable = [
        'requester_id', 'recipient_id', 'tag_id', 'topic', 'category', 'message',
        'duration_minutes', 'proposed_at', 'scheduled_at', 'status', 'response_message',
        'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'proposed_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }
}
