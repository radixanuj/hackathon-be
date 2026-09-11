<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person poking another. That is the whole feature.
 *
 * The rule that keeps it a conversation rather than a firehose is the one the
 * original poke used: once you have nudged someone you cannot nudge them again
 * until they nudge you back. An outstanding nudge — one with no `returned_at` —
 * is the lock, so the guard is a single indexed lookup rather than a rate limit
 * anyone has to tune.
 */
class Nudge extends Model
{
    protected $fillable = ['sender_id', 'recipient_id', 'streak', 'returned_at'];

    protected function casts(): array
    {
        return [
            'streak' => 'integer',
            'returned_at' => 'datetime',
        ];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    /** Still waiting on a nudge back. */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereNull('returned_at');
    }

    /** Every nudge between two people, in either direction. */
    public function scopeBetween(Builder $query, int $oneId, int $otherId): Builder
    {
        return $query->where(function (Builder $q) use ($oneId, $otherId) {
            $q->where(fn (Builder $sub) => $sub->where('sender_id', $oneId)->where('recipient_id', $otherId))
                ->orWhere(fn (Builder $sub) => $sub->where('sender_id', $otherId)->where('recipient_id', $oneId));
        });
    }

    /** Anything involving this person, sent or received. */
    public function scopeInvolving(Builder $query, int $userId): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('sender_id', $userId)->orWhere('recipient_id', $userId));
    }

    /** The most recent nudge between two people — the state of the exchange. */
    public static function latestBetween(int $oneId, int $otherId): ?self
    {
        return static::query()->between($oneId, $otherId)->orderByDesc('id')->first();
    }

    public function isOutstanding(): bool
    {
        return $this->returned_at === null;
    }

    /** The other party, seen from one side of the exchange. */
    public function counterpartId(int $userId): int
    {
        return $this->sender_id === $userId ? $this->recipient_id : $this->sender_id;
    }

    /**
     * Where an exchange stands for one person, in the shape the UI needs: can
     * they nudge, is it a nudge *back*, and how deep are they in.
     *
     * @return array{can_nudge: bool, waiting_on_them: bool, waiting_on_you: bool, streak: int, last_nudge_at: ?string}
     */
    public static function stateFor(int $userId, int $otherId): array
    {
        $latest = static::latestBetween($userId, $otherId);

        // Their nudge, unanswered: it is your turn, and nudging is nudging back.
        $waitingOnYou = (bool) $latest?->isOutstanding() && $latest->recipient_id === $userId;
        // Yours, unanswered: the lock is on until they reply.
        $waitingOnThem = (bool) $latest?->isOutstanding() && $latest->sender_id === $userId;

        return [
            'can_nudge' => $userId !== $otherId && ! $waitingOnThem,
            'waiting_on_them' => $waitingOnThem,
            'waiting_on_you' => $waitingOnYou,
            'streak' => $latest?->streak ?? 0,
            'last_nudge_at' => $latest?->created_at?->toIso8601String(),
        ];
    }
}
