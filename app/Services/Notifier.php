<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The one way anything in the app tells somebody that something happened.
 *
 * Call sites supply the wording, because that is where the context to write it
 * is; the type decides the pillar and the icon. Nothing is ever deleted — see
 * App\Models\Notification.
 */
class Notifier
{
    /**
     * Raise one notification.
     *
     * Returns null when there is nobody to tell: no recipient, a deactivated
     * account, or the recipient is the very person who caused the thing. Nobody
     * needs to be told about their own click.
     *
     * @param  array{actor?: User|int|null, title: string, body?: string|null,
     *               subject?: Model|null, action_url?: string|null, data?: array}  $attributes
     */
    public function send(User|int|null $recipient, string $type, array $attributes): ?Notification
    {
        $recipientId = $recipient instanceof User ? $recipient->id : $recipient;

        if (! $recipientId) {
            return null;
        }

        $actor = $attributes['actor'] ?? null;
        $actorId = $actor instanceof User ? $actor->id : $actor;

        if ($actorId && (int) $actorId === (int) $recipientId) {
            return null;
        }

        // A deactivated account has no inbox worth filling.
        if (! $this->isReachable($recipient, $recipientId)) {
            return null;
        }

        $subject = $attributes['subject'] ?? null;

        return Notification::create([
            'user_id' => $recipientId,
            'actor_id' => $actorId,
            'type' => $type,
            'category' => Notification::categoryFor($type),
            'title' => $attributes['title'],
            'body' => $attributes['body'] ?? null,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'action_url' => $attributes['action_url'] ?? null,
            'data' => $attributes['data'] ?? null,
        ]);
    }

    /**
     * Raise the same notification for a group of people.
     *
     * Takes users or bare ids, drops duplicates, and leans on send() to skip the
     * actor — so "tell everyone who RSVP'd" can be written without first
     * remembering to exclude the host who triggered it.
     *
     * @param  iterable<User|int>  $recipients
     * @return Collection<int, Notification>
     */
    public function sendMany(iterable $recipients, string $type, array $attributes): Collection
    {
        return collect($recipients)
            ->map(fn ($recipient) => $recipient instanceof User ? $recipient->id : (int) $recipient)
            ->filter()
            ->unique()
            ->map(fn (int $id) => $this->send($id, $type, $attributes))
            ->filter()
            ->values();
    }

    /**
     * A User we were handed carries its own `is_active`; a bare id costs one
     * lookup, which is cheaper than loading the model at every call site.
     */
    protected function isReachable(User|int|null $recipient, int $recipientId): bool
    {
        if ($recipient instanceof User) {
            return (bool) $recipient->is_active;
        }

        return User::whereKey($recipientId)->where('is_active', true)->exists();
    }
}
