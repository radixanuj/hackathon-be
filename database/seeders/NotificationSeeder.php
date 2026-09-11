<?php

namespace Database\Seeders;

use App\Models\Ama;
use App\Models\CoffeeInvite;
use App\Models\Event;
use App\Models\InterestGroup;
use App\Models\Notification;
use App\Models\OfficeHourSlot;
use App\Models\OpenInvite;
use App\Models\Recommendation;
use App\Models\SessionRequest;
use App\Models\Story;
use App\Models\TeachOffer;
use App\Services\Notifier;
use Illuminate\Database\Seeder;

/**
 * Backfills the inboxes for everything the other seeders created directly.
 *
 * The rest of the seed writes models rather than going through the controllers,
 * so none of it raises a notification on the way in and every demo account would
 * otherwise start with an empty bell. This walks the seeded relationships and
 * raises exactly the notifications those actions would have raised live — same
 * service, same wording — then backdates them and marks a believable share as
 * already read or archived.
 */
class NotificationSeeder extends Seeder
{
    public function run(Notifier $notifier): void
    {
        $before = Notification::max('id') ?? 0;

        $this->fromEvents($notifier);
        $this->fromStories($notifier);
        $this->fromRecommendations($notifier);
        $this->fromGroups($notifier);
        $this->fromAmas($notifier);
        $this->fromSessionRequests($notifier);
        $this->fromCoffeeInvites($notifier);
        $this->fromOfficeHours($notifier);
        $this->fromTeachOffers($notifier);
        $this->fromOpenInvites($notifier);

        $this->age($before);
    }

    protected function fromEvents(Notifier $notifier): void
    {
        Event::with(['rsvps.user'])->get()->each(function (Event $event) use ($notifier) {
            foreach ($event->rsvps->where('user_id', '!=', $event->host_id)->take(4) as $rsvp) {
                $notifier->send($event->host_id, 'event.rsvp', [
                    'actor' => $rsvp->user,
                    'title' => $rsvp->user->name.' '.match ($rsvp->status) {
                        'going' => 'is coming to',
                        'maybe' => 'might come to',
                        default => 'cannot make',
                    }.' '.$event->title,
                    'body' => $event->going_count.' going so far.',
                    'subject' => $event,
                    'action_url' => '/community?tab=events',
                ]);
            }
        });
    }

    protected function fromStories(Notifier $notifier): void
    {
        Story::with('reactions.user')->get()->each(function (Story $story) use ($notifier) {
            foreach ($story->reactions->take(3) as $reaction) {
                $notifier->send($story->user_id, 'story.reaction', [
                    'actor' => $reaction->user,
                    'title' => $reaction->user->name.' reacted to your story',
                    'body' => $story->title,
                    'subject' => $story,
                    'action_url' => '/community?tab=stories',
                    'data' => ['reaction' => $reaction->reaction],
                ]);
            }
        });
    }

    protected function fromRecommendations(Notifier $notifier): void
    {
        Recommendation::with('likes.user')->get()->each(function (Recommendation $rec) use ($notifier) {
            foreach ($rec->likes->take(2) as $like) {
                $notifier->send($rec->user_id, 'recommendation.liked', [
                    'actor' => $like->user,
                    'title' => $like->user->name.' liked your recommendation',
                    'body' => $rec->title,
                    'subject' => $rec,
                    'action_url' => '/community?tab=learn',
                ]);
            }
        });
    }

    protected function fromGroups(Notifier $notifier): void
    {
        InterestGroup::with('members')->get()->each(function (InterestGroup $group) use ($notifier) {
            foreach ($group->members->where('id', '!=', $group->created_by)->take(3) as $member) {
                $notifier->send($group->created_by, 'group.joined', [
                    'actor' => $member,
                    'title' => $member->name.' joined '.$group->name,
                    'body' => $group->members_count.' members now.',
                    'subject' => $group,
                    'action_url' => '/community?tab=groups',
                ]);
            }
        });
    }

    protected function fromAmas(Notifier $notifier): void
    {
        Ama::with('questions.user')->get()->each(function (Ama $ama) use ($notifier) {
            foreach ($ama->questions->take(3) as $question) {
                $notifier->send($ama->host_id, 'ama.question_asked', [
                    'actor' => $question->user,
                    'title' => $question->user->name.' asked you something',
                    'body' => $question->body,
                    'subject' => $ama,
                    'action_url' => '/community?tab=learn',
                ]);
            }
        });
    }

    protected function fromSessionRequests(Notifier $notifier): void
    {
        SessionRequest::with(['requester', 'recipient'])->get()->each(function (SessionRequest $sr) use ($notifier) {
            $notifier->send($sr->recipient_id, 'session_request.received', [
                'actor' => $sr->requester,
                'title' => $sr->requester->name.' asked you for '.$sr->duration_minutes.' minutes',
                'body' => $sr->topic,
                'subject' => $sr,
                'action_url' => '/connect?tab=mentoring',
            ]);

            // Anything already answered also told the person who asked.
            if (in_array($sr->status, ['accepted', 'declined', 'time_suggested'], true)) {
                $when = $sr->scheduled_at?->format('D j M, g:ia');

                $notifier->send($sr->requester_id, 'session_request.'.$sr->status, [
                    'actor' => $sr->recipient,
                    'title' => $sr->recipient->name.' '.match ($sr->status) {
                        'accepted' => 'said yes',
                        'declined' => 'passed on your request',
                        default => 'suggested another time',
                    },
                    'body' => $when ? $sr->topic.' — '.$when : $sr->topic,
                    'subject' => $sr,
                    'action_url' => '/connect?tab=mentoring',
                ]);
            }
        });
    }

    protected function fromCoffeeInvites(Notifier $notifier): void
    {
        CoffeeInvite::with('joins.user')->get()->each(function (CoffeeInvite $invite) use ($notifier) {
            foreach ($invite->joins->take(3) as $join) {
                $notifier->send($invite->host_id, 'coffee_invite.joined', [
                    'actor' => $join->user,
                    'title' => $join->user->name.' is joining your '.$invite->kind,
                    'body' => implode(' · ', array_filter([
                        $invite->starts_at?->format('D j M, g:ia'),
                        $invite->is_virtual ? 'Virtual' : $invite->location,
                    ])),
                    'subject' => $invite,
                    'action_url' => '/connect?tab=coffee',
                ]);
            }
        });
    }

    protected function fromOfficeHours(Notifier $notifier): void
    {
        OfficeHourSlot::with('bookings.user')->get()->each(function (OfficeHourSlot $slot) use ($notifier) {
            foreach ($slot->bookings->where('status', 'booked')->take(2) as $booking) {
                $notifier->send($slot->host_id, 'office_hours.booked', [
                    'actor' => $booking->user,
                    'title' => $booking->user->name.' booked your office hours',
                    'body' => $booking->topic ?: $slot->starts_at?->format('D j M, g:ia'),
                    'subject' => $slot,
                    'action_url' => '/connect?tab=office-hours',
                ]);
            }
        });
    }

    protected function fromTeachOffers(Notifier $notifier): void
    {
        TeachOffer::with('interests.user')->get()->each(function (TeachOffer $offer) use ($notifier) {
            foreach ($offer->interests->take(3) as $interest) {
                $short = $offer->interested_count < $offer->min_interested
                    ? ($offer->min_interested - $offer->interested_count).' more and you can put a date on it.'
                    : 'That is enough interest — you can schedule it now.';

                $notifier->send($offer->user_id, 'teach_offer.interest', [
                    'actor' => $interest->user,
                    'title' => $interest->user->name.' wants to learn '.$offer->title,
                    'body' => $short,
                    'subject' => $offer,
                    'action_url' => '/community?tab=ask-teach',
                ]);
            }
        });
    }

    protected function fromOpenInvites(Notifier $notifier): void
    {
        OpenInvite::with('interests.user')->get()->each(function (OpenInvite $invite) use ($notifier) {
            foreach ($invite->interests->where('user_id', '!=', $invite->user_id)->take(3) as $interest) {
                $notifier->send($invite->user_id, 'open_invite.interest', [
                    'actor' => $interest->user,
                    'title' => $interest->user->name.' is in for '.$invite->title,
                    'body' => $invite->interested_count.' interested so far.',
                    'subject' => $invite,
                    'action_url' => '/community?tab=events',
                ]);
            }
        });
    }

    /**
     * Spread the backfill over the last fortnight and settle it, so a demo inbox
     * opens on a plausible mix rather than fifty unread rows all stamped now.
     */
    protected function age(int $afterId): void
    {
        Notification::where('id', '>', $afterId)->get()->each(function (Notification $n) {
            $at = now()->subMinutes(random_int(5, 60 * 24 * 14));

            $n->forceFill([
                'created_at' => $at,
                'updated_at' => $at,
                // Older things have mostly been seen; the last day or two has not.
                'read_at' => $at->lessThan(now()->subDay()) && random_int(1, 10) > 2
                    ? $at->copy()->addMinutes(random_int(5, 600))
                    : null,
            ])->save();

            // A handful are filed away, so the Archived tab is not empty either.
            if ($n->read_at && random_int(1, 5) === 1) {
                $n->forceFill(['archived_at' => $n->read_at->copy()->addHours(random_int(1, 48))])->save();
            }
        });
    }
}
