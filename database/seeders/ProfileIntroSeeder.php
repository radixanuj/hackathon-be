<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Writes the "About me" line on every profile that hasn't got one.
 *
 * The org-chart export carries no intro, so without this the roster renders with
 * seventy blank About me sections — and that line is doing real work in the UI.
 * Home quotes its first sentence under "who should I meet", the mentoring list
 * shows it under every name, and the profile modal leads with it. A blank there
 * reads as an empty product; Latin filler reads as a broken one.
 *
 * An intro is assembled rather than picked whole: an opening written for the kind
 * of job the person actually does, the skills they actually listed, and a line
 * about something they're actually into. The pick is a hash of the person's name,
 * so a reseed lands on the same intro instead of quietly rewriting everybody.
 *
 * Only ever fills a blank. An intro somebody typed during a demo survives.
 */
class ProfileIntroSeeder extends Seeder
{
    /**
     * Openings for the senior ranks, chosen ahead of the team pools below.
     *
     * A Senior Director writing "two years in and still finding my feet" is the
     * kind of wrong that a reader notices immediately, so rank wins over team.
     */
    protected const BY_RANK = [
        'chief' => [
            'I have been here since the beginning, which mostly means I remember which of our best ideas started as bad ones.',
            'My job is to keep us pointed at the right problem for long enough to actually solve it. Some years that goes better than others.',
            'Genuinely open door — if something about how we work does not make sense to you, I would rather hear it early.',
        ],
        'vice president' => [
            'I spend most of my week on the two-year question rather than the two-week one, which takes some getting used to.',
            'Long enough here to have watched three strategies through from argument to launch to retirement.',
            'Most of my job is making sure the people doing the actual work have a clear enough problem to work on.',
        ],
        'director' => [
            'I look after a fairly broad patch, which means I know a little about a lot and will happily point you at whoever knows more.',
            'Most of what I do now is unblocking other people, which is a better use of me than anything I was doing five years ago.',
            'Come to me with the half-formed version. I would much rather see it early than see it polished and wrong.',
            'I have run this area long enough to have strong opinions and enough scars to hold them loosely.',
            'I spend more of my week on hiring and on other people\'s problems than on anything I would call my own work, and that is the job.',
            'Twelve years of watching good plans meet reality has made me a lot more patient about the second version.',
            'The best hour of my week is usually the one I did not have booked. Come and use it.',
            'I would rather be told something is going wrong early and badly than late and neatly.',
        ],
        'manager' => [
            'I run a small team and spend a lot of my week making sure they have what they need and then getting out of the way.',
            'Half my calendar is one-on-ones and I would not give any of them up. The other half is meetings I am still trying to delete.',
            'Came up through the work before doing this, so I am still close enough to it to be useful in a review.',
            'First time managing was harder than anyone told me it would be, so I am happy to be honest with anyone about to try it.',
            'Most of my job is making sure nobody on my team is quietly stuck on something for a week.',
            'I still pick up pieces of the work myself, mostly so I do not lose the plot entirely.',
        ],
    ];

    /** Openings written for the kind of work the team actually does. */
    protected const BY_TEAM = [
        'Engineering' => [
            'Mostly backend, mostly the unglamorous parts that everything else sits on top of.',
            'I work across the API and the bits of infrastructure nobody wants to own. Ask me before you add another queue.',
            'Frontend, mostly. I care more about how something feels to use than about which framework it is built in.',
            'I have touched most of this codebase at some point, which is a mixed blessing. Happy to give anyone the tour.',
            'Spend most of my time on data pipelines and the quiet ways they break. Come and find me when a number looks wrong.',
            'Joined to write code and have ended up doing at least as much reviewing of it, which turns out to be the better job.',
        ],
        'Design' => [
            'I design the screens people actually spend their day in, which is less glamorous and more interesting than the marketing work.',
            'Come to me early with the rough version. A design review after everything is built is not a design review.',
            'Brand and product both, which means I spend a lot of time arguing that they should not look like two different companies.',
            'I care a great deal about type and spacing, and I have made peace with the fact that this is not universal.',
            'Most of my job is asking "who is this actually for" until somebody answers properly.',
        ],
        'Marketing' => [
            'I work on how we sound — campaigns, landing pages, and the endless argument about how much copy is too much copy.',
            'Mostly performance side. I can tell you what a channel is really costing us and why the dashboard disagrees.',
            'Content and comms. If you need something written, edited, or talked out of being written, I am your person.',
            'I have been on the brand side long enough to be suspicious of any idea that sounds clever in a meeting.',
            'Spend most of my week between the data and the creative, translating one into the other.',
        ],
        'Data' => [
            'I build the reporting most teams open on a Monday, so if a number looks wrong it is probably mine and I want to know.',
            'Mostly modelling and pipelines. I would rather spend a week on the definition than a quarter on the dashboard.',
            'Happy to sit with anyone who has a question they think is too basic to ask about our data. It is not.',
            'I spend a lot of time explaining what a number does not mean, which is most of the job.',
        ],
        'People' => [
            'I look after hiring end to end, which means I read a great many CVs and talk to even more people.',
            'Most of my week is spent on the things that make the difference quietly — onboarding, feedback, the awkward conversations.',
            'Come to me about anything, genuinely. Half of what I do is knowing who to point you at.',
            'I care about making the first ninety days here good, because almost everything else follows from that.',
        ],
        'Support' => [
            'I talk to registrars on their worst day, which means I know exactly where our product confuses people.',
            'Six years of tickets have given me an unreasonably detailed map of what actually breaks and how often.',
            'If you are building something customer-facing, come and talk to me before it ships rather than after.',
            'I am the person who finds out first when something is wrong, and I would rather be the person who tells you.',
        ],
        'Finance' => [
            'I build the models the plan gets argued over, and I am always happy to walk someone through how a number was put together.',
            'Mostly forecasting and analysis. I would rather explain the assumptions than defend the output.',
            'Ask me anything about how the business actually makes money — most people here have a rough idea and not much more.',
            'I have a low tolerance for spreadsheets nobody can follow, including my own from two years ago.',
        ],
        'Partnerships' => [
            'I spend my week on the phone to partners, which is a job made almost entirely of remembering what people told you last time.',
            'Most of what I do is negotiation, and most of negotiation is preparation nobody sees.',
            'I know the registrar landscape well enough to tell you who is worth talking to and who is not.',
            'Happy to take anyone on a call to hear how this side of the business actually works.',
        ],
        'Programs' => [
            'I keep large, slow things moving, which is less about project plans than about asking the same question until it gets answered.',
            'Most of my job is finding the thing that is quietly blocked and making it somebody\'s problem.',
            'I sit between three teams that all think they are waiting on each other. Usually they are.',
            'Ask me who owns something. I probably know, and if I do not I will find out.',
        ],
        'Operations' => [
            'I look after the processes everyone relies on and nobody thinks about until they stop working.',
            'Most of what I do is removing a step from something. It is more satisfying than it sounds.',
            'Come to me when a process is getting in your way — that usually means it needs fixing, not following harder.',
        ],
        'Trust & Safety' => [
            'I work on abuse and enforcement, which is the part of domains people outside it rarely hear about.',
            'My job is the small percentage of registrations that cause the overwhelming majority of the problems.',
            'Happy to explain how our takedown process actually works — it surprises most people.',
        ],
        'Leadership' => [
            'Long enough here to have seen most things twice. Happy to be a sounding board for anyone on anything.',
            'Most useful thing I do now is connect two people who should have been talking already.',
        ],
        'Advisory' => [
            'I come in from the outside, which means my main value is asking the obvious question nobody inside wants to ask.',
            'Here a few days a month. Best used on the problems that have been stuck for a while.',
        ],
    ];

    /** For anyone whose team the export put somewhere we have not written copy for. */
    protected const GENERIC = [
        'A few years in and still finding corners of this place I did not know about.',
        'I like work that involves talking to people outside my own team, so please do interrupt me.',
        'Reasonably new here, so mostly asking questions and writing down the answers.',
        'Happy to talk to anyone about anything, including things well outside my job description.',
    ];

    /** Skills a person listed, phrased as an offer rather than a list. */
    protected const OFFERS = [
        'Ask me about %s.',
        'Happiest talking about %s.',
        'If you need a hand with %s, just ask.',
        'Come and find me about %s.',
        'I can usually help with %s.',
    ];

    /** A closing line per interest, so "outside work" says something specific. */
    protected const OUTSIDE = [
        'F1' => 'Awake at unreasonable hours for most races.',
        'Cricket' => 'I will talk about cricket for considerably longer than you want me to.',
        'Books' => 'Always halfway through two books and finishing neither.',
        'Running' => 'Running most mornings, slowly and without much dignity.',
        'Trekking' => 'Somewhere in the Sahyadris most long weekends.',
        'Photography' => 'Camera with me more often than not.',
        'AI' => 'Currently building small, useless things with LLMs on weekends.',
        'Movies' => 'I still see most things in a cinema rather than at home.',
        'Gaming' => 'Slowly working through a backlog I will never finish.',
        'Cooking' => 'I cook to wind down and buy far more spices than anyone needs.',
        'Chess' => 'Rapid games on the commute, stuck at the same rating for two years.',
        'Cycling' => 'Out on the bike early on Sundays.',
        'Music' => 'Learning an instrument badly, for the third time.',
        'Board Games' => 'I own too many board games and will happily teach you any of them.',
        'Travel' => 'Always planning a trip I have not booked yet.',
    ];

    public function run(): void
    {
        User::with(['tags'])->get()->each(function (User $user) {
            if (trim((string) $user->intro) !== '') {
                return;
            }

            $user->update(['intro' => $this->composeFor($user)]);
        });
    }

    /** Opening, what they can help with, and one thing outside work. */
    protected function composeFor(User $user): string
    {
        $parts = [$this->opening($user)];

        if ($offer = $this->offer($user)) {
            $parts[] = $offer;
        }

        // Not everyone volunteers a personal line, and a roster where all seventy
        // people do reads as generated. Roughly two in three is about right.
        if ($this->roll($user, 'outside') < 66 && $outside = $this->outside($user)) {
            $parts[] = $outside;
        }

        return implode(' ', $parts);
    }

    protected function opening(User $user): string
    {
        $title = mb_strtolower((string) $user->job_title);

        foreach (self::BY_RANK as $rank => $lines) {
            if (str_contains($title, $rank)) {
                return $this->pick($lines, $user, 'rank');
            }
        }

        return $this->pick(self::BY_TEAM[$user->team] ?? self::GENERIC, $user, 'team');
    }

    /** One or two of the skills they actually listed, read back as an offer. */
    protected function offer(User $user): ?string
    {
        $skills = $this->tagNames($user, ['can_help_with', 'can_talk_about']);

        if ($skills->isEmpty()) {
            return null;
        }

        $named = $skills->take($this->roll($user, 'count') < 50 ? 1 : 2);

        return sprintf(
            $this->pick(self::OFFERS, $user, 'offer'),
            $named->count() === 2 ? $named->first().' and '.$named->last() : $named->first(),
        );
    }

    protected function outside(User $user): ?string
    {
        $interest = $this->tagNames($user, ['interest'])
            ->first(fn (string $name) => isset(self::OUTSIDE[$name]));

        return $interest ? self::OUTSIDE[$interest] : null;
    }

    /** @return Collection<int, string> */
    protected function tagNames(User $user, array $kinds): Collection
    {
        return $user->tags
            ->filter(fn ($tag) => in_array($tag->pivot->kind, $kinds, true))
            ->pluck('name')
            ->unique()
            ->values();
    }

    protected function pick(array $options, User $user, string $salt): string
    {
        return $options[$this->roll($user, $salt) % count($options)];
    }

    /** A stable 0-99 roll per person, so a reseed produces the same intro. */
    protected function roll(User $user, string $salt): int
    {
        return crc32($user->name.'|'.$user->team.'|intro|'.$salt) % 100;
    }
}
