<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SessionRequest extends Model
{
    public const CATEGORIES = [
        'work_knowledge', 'career', 'leadership', 'people', 'technical', 'personal_experience',
    ];

    /**
     * What the asker came in through.
     *
     * knowledge - one topic, one half hour, usually off the back of a skill tag.
     * mentoring - a standing conversation about where somebody is heading.
     * coaching  - a few focused sessions on one thing they want to get better at.
     */
    public const KINDS = ['knowledge', 'mentoring', 'coaching'];

    protected $fillable = [
        'requester_id', 'recipient_id', 'kind', 'tag_id', 'topic', 'category', 'message',
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
