<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Something that happened which one person needs to know about.
 *
 * Rows are never deleted. `read_at` records that it has been seen and
 * `archived_at` takes it out of the inbox — archived notifications stay
 * readable under their own tab for good.
 */
class Notification extends Model
{
    /**
     * Every notification the app can raise, mapped to the pillar it belongs to
     * and the emoji the frontend leads with. Adding a type here is what makes it
     * filterable and routable; the wording itself lives at the call site, where
     * the context to write it is.
     *
     * The rule for whether something belongs on this list: the recipient must
     * already have a stake in it — it is theirs, or they signed up for it, or
     * they were matched with someone. Nothing here broadcasts to people just
     * because a topic or a group looked like a fit; that is what the discovery
     * feeds are for, and a notification they never asked for is only noise.
     */
    public const TYPES = [
        // --- People ---------------------------------------------------------
        'quest.completed' => ['people', '🎯'],
        'nudge.received' => ['people', '👉'],

        // --- Connect --------------------------------------------------------
        'session_request.received' => ['connect', '🤝'],
        'session_request.accepted' => ['connect', '✅'],
        'session_request.declined' => ['connect', '💬'],
        'session_request.time_suggested' => ['connect', '🕐'],
        'session_request.cancelled' => ['connect', '🚫'],
        'session_request.completed' => ['connect', '🎉'],
        'meetup.matched' => ['connect', '☕'],
        'meetup.unmatched' => ['connect', '🤞'],
        'buddy.matched' => ['connect', '🌍'],
        'buddy.ended' => ['connect', '👋'],
        'office_hours.booked' => ['connect', '📅'],
        'office_hours.booking_cancelled' => ['connect', '🚫'],
        'office_hours.slot_cancelled' => ['connect', '🚫'],
        'coffee_invite.joined' => ['connect', '☕'],
        'coffee_invite.left' => ['connect', '👋'],
        'coffee_invite.cancelled' => ['connect', '🚫'],

        // --- Communities ----------------------------------------------------
        'group.joined' => ['communities', '👥'],
        'challenge.joined' => ['communities', '🏃'],
        'challenge.overtaken' => ['communities', '📈'],

        // --- Learn & Share --------------------------------------------------
        'recommendation.liked' => ['learn', '❤️'],
        'ama.question_asked' => ['learn', '🙋'],
        'ama.question_answered' => ['learn', '💡'],
        'question.answered' => ['learn', '💡'],
        'question.volunteered' => ['learn', '🙋'],
        'question.answer_accepted' => ['learn', '🏆'],
        'teach_offer.interest' => ['learn', '✋'],
        'teach_offer.scheduled' => ['learn', '📅'],

        // --- Do Together ----------------------------------------------------
        'event.rsvp' => ['do_together', '🙌'],
        'event.updated' => ['do_together', '✏️'],
        'event.cancelled' => ['do_together', '🚫'],
        'open_invite.interest' => ['do_together', '✋'],
        'open_invite.converted' => ['do_together', '🎉'],

        // --- Celebrate & Discover -------------------------------------------
        'story.reaction' => ['celebrate', '👏'],
    ];

    public const CATEGORIES = [
        'people', 'connect', 'communities', 'learn', 'do_together', 'celebrate',
    ];

    protected $fillable = [
        'user_id', 'actor_id', 'type', 'category', 'title', 'body',
        'subject_type', 'subject_id', 'action_url', 'data', 'read_at', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** Everything not archived — what the bell counts and the inbox lists. */
    public function scopeInbox(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function markRead(): void
    {
        if (! $this->read_at) {
            $this->update(['read_at' => now()]);
        }
    }

    public function markUnread(): void
    {
        $this->update(['read_at' => null]);
    }

    /** Out of the inbox, into Archived. Reading it is implied by filing it away. */
    public function archive(): void
    {
        $this->update([
            'archived_at' => $this->archived_at ?? now(),
            'read_at' => $this->read_at ?? now(),
        ]);
    }

    public function unarchive(): void
    {
        $this->update(['archived_at' => null]);
    }

    /** The emoji the frontend leads the row with. */
    public function icon(): string
    {
        return self::TYPES[$this->type][1] ?? '🔔';
    }

    public static function categoryFor(string $type): string
    {
        return self::TYPES[$type][0] ?? 'people';
    }
}
