<?php

namespace Database\Seeders;

use App\Models\Nudge;
use App\Models\User;
use App\Services\Notifier;
use Illuminate\Database\Seeder;

/**
 * A plausible fortnight of nudges, so the feature is not an empty screen on
 * day one.
 *
 * Runs last and raises its notifications as it goes, the same way the live
 * endpoint would — including the streak wording, which is the only part of a
 * nudge that carries any information at all.
 *
 * The demo account is dealt with first and on purpose: whoever signs in as the
 * admin should find nudges already waiting on them, a couple they have already
 * answered, and one exchange deep enough to show what a streak looks like.
 */
class NudgeSeeder extends Seeder
{
    public function run(Notifier $notifier): void
    {
        $everyone = User::query()->active()->get();

        if ($everyone->count() < 4) {
            return;
        }

        $admin = $everyone->firstWhere('role', 'admin');
        $spokenFor = collect();

        if ($admin) {
            $others = $everyone->where('id', '!=', $admin->id)->shuffle()->values();
            // Their side of this is composed deliberately below; the random
            // pairing afterwards must not add to it.
            $spokenFor->push($admin->id);

            // Three nudges sitting unanswered — the state worth demoing, and
            // what the bell should be showing on a fresh sign-in.
            foreach ($others->take(3) as $person) {
                $this->exchange($notifier, $person, $admin, 1);
                $spokenFor->push($person->id);
            }

            // Two the admin has already nudged back, so the other side of the
            // list is not empty either.
            foreach ($others->slice(3, 2) as $person) {
                $this->exchange($notifier, $person, $admin, 2);
                $spokenFor->push($person->id);
            }

            // One long-running back-and-forth. Six deep reads as a running joke
            // between two people, which is exactly what a poke was for.
            if ($friend = $others->slice(5)->first()) {
                $this->exchange($notifier, $admin, $friend, 6);
                $spokenFor->push($friend->id);
            }
        }

        // Everybody else, in pairs: mostly one-way, some answered once or twice.
        $pool = $everyone->whereNotIn('id', $spokenFor->all())->shuffle()->values();

        for ($i = 0; $i + 1 < $pool->count() && $i < 30; $i += 2) {
            $this->exchange($notifier, $pool[$i], $pool[$i + 1], random_int(1, 3));
        }
    }

    /**
     * Play out one exchange between two people, oldest nudge first.
     *
     * `$turns` is how many nudges deep it goes: the starter sends the odd ones
     * and the other person nudges back on the even ones, so an odd count leaves
     * the ball in the other person's court. Rows are backdated and spaced out,
     * because a hundred nudges stamped the same second is not a demo of
     * anything.
     */
    protected function exchange(Notifier $notifier, User $starter, User $other, int $turns): void
    {
        if ($starter->id === $other->id) {
            return;
        }

        $at = now()->subDays(random_int(3, 12));
        $previous = null;

        for ($turn = 1; $turn <= $turns; $turn++) {
            // Odd turns are the starter's, even turns are the reply.
            [$sender, $recipient] = $turn % 2 === 1 ? [$starter, $other] : [$other, $starter];

            // Answering their nudge is what takes it out of the outstanding pile.
            $previous?->forceFill(['returned_at' => $at])->save();

            $nudge = Nudge::create([
                'sender_id' => $sender->id,
                'recipient_id' => $recipient->id,
                'streak' => ($previous?->streak ?? 0) + 1,
            ]);
            $nudge->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

            $notification = $notifier->send($recipient, 'nudge.received', [
                'actor' => $sender,
                'title' => $sender->name.' nudged you',
                'body' => $nudge->streak > 1
                    ? "That is {$nudge->streak} nudges between you two. Nudge back?"
                    : 'No reason, no agenda. Nudge back?',
                'subject' => $nudge,
                'action_url' => '/people?profile='.$sender->id,
                'data' => ['streak' => $nudge->streak],
            ]);

            // Sit it under the same date as the nudge it is about. NotificationSeeder
            // has already run, so nothing will come along afterwards and re-age it.
            $notification?->forceFill([
                'created_at' => $at,
                'updated_at' => $at,
                // A nudge you went on to answer is one you plainly saw.
                'read_at' => $turn < $turns ? $at->copy()->addMinutes(random_int(2, 90)) : null,
            ])->save();

            $previous = $nudge;
            $at = $at->copy()->addHours(random_int(3, 40));
        }
    }
}
