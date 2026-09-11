<?php

namespace Database\Seeders;

use App\Models\BuddySignup;
use App\Models\Challenge;
use App\Models\CoffeeInvite;
use App\Models\MeetupRound;
use App\Models\OfficeHourSlot;
use App\Models\OpenInvite;
use App\Models\RadixQuestion;
use App\Models\SessionRequest;
use App\Models\Story;
use App\Models\Tag;
use App\Models\TeachOffer;
use App\Models\User;
use App\Services\BlindMeetupMatcher;
use App\Services\BuddyMatcher;
use App\Services\QuestBuilder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds a believable Radix for demoing every Phase 1 pillar.
 *
 * Every string this file writes is copy somebody could plausibly have typed into
 * the product. Faker's lorem generators are deliberately absent: a demo where the
 * stories, answers and descriptions are Latin filler tells a viewer nothing about
 * what the screen is for, and the body copy is most of what these screens are.
 * Where a row needs prose, the prose is written out next to the row it belongs to.
 */
class DatabaseSeeder extends Seeder
{
    protected const SKILLS = [
        'BigQuery', 'Laravel', 'React', 'Product Discovery', 'SEO', 'Domain Strategy',
        'Hiring', 'Public Speaking', 'Data Modelling', 'Figma', 'Kubernetes', 'Copywriting',
        'Negotiation', 'Managing First Time', 'Financial Modelling', 'Customer Interviews',
    ];

    protected const INTERESTS = [
        'F1', 'Cricket', 'Books', 'Running', 'Trekking', 'Photography', 'AI', 'Movies',
        'Gaming', 'Cooking', 'Chess', 'Cycling', 'Music', 'Board Games', 'Travel',
    ];

    /**
     * The skills each team plausibly carries.
     *
     * Handing everybody three skills at random is the same failure as lorem copy,
     * one level down: it puts Kubernetes on a Finance director, routes Ask Radix
     * to the wrong people, and fills "who can talk about Negotiation" with names
     * that make no sense. Drawing from the team's own column keeps the expertise
     * sections readable for the forty-odd colleagues the skills export misses.
     */
    protected const TEAM_SKILLS = [
        'Engineering' => ['Laravel', 'React', 'Kubernetes', 'Data Modelling', 'BigQuery'],
        'Data' => ['BigQuery', 'Data Modelling', 'Product Discovery', 'Financial Modelling'],
        'Design' => ['Figma', 'Product Discovery', 'Customer Interviews', 'Copywriting'],
        'Marketing' => ['SEO', 'Copywriting', 'Public Speaking', 'Customer Interviews', 'Domain Strategy'],
        'Partnerships' => ['Negotiation', 'Domain Strategy', 'Public Speaking', 'Customer Interviews'],
        'Finance' => ['Financial Modelling', 'Negotiation', 'Data Modelling'],
        'People' => ['Hiring', 'Managing First Time', 'Public Speaking', 'Negotiation'],
        'Support' => ['Customer Interviews', 'Domain Strategy', 'Public Speaking'],
        'Programs' => ['Product Discovery', 'Public Speaking', 'Managing First Time'],
        'Operations' => ['Data Modelling', 'Negotiation', 'Managing First Time'],
        'Trust & Safety' => ['Domain Strategy', 'Negotiation', 'Data Modelling'],
        'Leadership' => ['Hiring', 'Negotiation', 'Domain Strategy', 'Financial Modelling'],
        'Advisory' => ['Hiring', 'Negotiation', 'Public Speaking'],
    ];

    protected const DEFAULT_SKILLS = ['Public Speaking', 'Product Discovery', 'Customer Interviews'];

    /** Ranks where managing people is part of the job, and so part of the profile. */
    protected const MANAGING_RANKS = ['manager', 'director', 'vice president', 'chief'];

    public function run(): void
    {
        $skills = collect(self::SKILLS)->map(fn ($n) => Tag::findOrCreateByName($n, 'skill'));
        $interests = collect(self::INTERESTS)->map(fn ($n) => Tag::findOrCreateByName($n, 'interest'));

        // The real roster, so every pillar below is populated by actual colleagues.
        // It spans six offices, so the cross-office pillars are covered by it too.
        $this->call(EmployeeSeeder::class);

        $everyone = User::query()->get();
        $newJoiners = $everyone->filter(fn (User $u) => $u->isNewJoiner());

        // Profiles: the four sections that answer "why talk to this person?"
        $everyone->each(function (User $user) use ($skills, $interests) {
            $theirs = $this->skillsFor($user, $skills);

            $this->attach($user, $theirs->take(3), 'can_talk_about');
            $this->attach($user, $theirs->take(2), 'can_help_with');
            // Deliberately from outside their own column: wanting to learn the thing
            // you already do all day is the one combination that reads as generated.
            $this->attach($user, $skills->reject(fn (Tag $t) => $theirs->contains('id', $t->id))->random(2), 'want_to_learn');
            $this->attach($user, $interests->random(3), 'interest');
        });

        // Real skills where we have them, replacing the random pair of expertise
        // sections above. Before the usage counts are totted up, not after.
        $this->call(SkillSeeder::class);

        Tag::all()->each(fn (Tag $tag) => $tag->update(['usage_count' => $tag->users()->count()]));

        // The curated lists on top of all that: who mentors, and which topics the
        // Knowledge Session card offers. Last, so it has the full roster and the
        // real skills to work against.
        $this->call(MentoringSeeder::class);

        // Profiles now have their tags, so an intro can be written around them.
        $this->call(ProfileIntroSeeder::class);

        // And the "Currently into" card, drawn from the same interests.
        $this->call(CurrentlySeeder::class);

        // New Joiner Quest for everyone who just arrived.
        $builder = app(QuestBuilder::class);
        $newJoiners->each(fn (User $user) => $builder->buildFor($user));

        $this->seedMeetups($everyone);
        $this->seedSessions($everyone);

        // Groups, Events, Recommendations, AMAs and After Hrs stories - the whole
        // of Find Your Crowd - come off the design canvas rather than from here.
        $this->call(CrowdSeeder::class);

        // --- Phase 2 ---
        $this->seedBuddies($everyone);
        $this->seedOfficeHours($everyone);
        $this->seedCoffeeInvites($everyone);
        $this->seedChallenges($everyone, $interests);
        $this->seedAskRadix($everyone, $skills);
        $this->seedTeachOffers($everyone, $skills);
        $this->seedOpenInvites($everyone);
        $this->tagStories($interests);

        // Everything above picks its hosts and authors at random, which regularly
        // leaves the admin account - the one the docs hand out and the one a demo
        // signs in as - with an empty bell, an empty Me page and nothing of their
        // own on any tab. Before the notifications are backfilled, put them in it.
        $this->seedDemoAccountActivity($everyone);

        // Last: everything above writes models directly rather than going through
        // the controllers, so nothing has raised a notification yet.
        $this->call(NotificationSeeder::class);

        // After the backfill, because it raises and dates its own notifications
        // alongside the nudges they belong to.
        $this->call(NudgeSeeder::class);
    }

    protected function seedBuddies($everyone): void
    {
        $notes = [
            'Happy to talk to anyone, ideally someone nowhere near my team.',
            'Usually free after 6pm IST, but I can do early if that helps the time zones.',
            'Would love someone on the commercial side — I only ever talk to engineers.',
            'Fairly new here, so mostly curious what everyone else actually does all day.',
            'No preferences at all. Surprise me.',
            'Second time doing this. The first one turned into a standing monthly call.',
            null,
        ];

        // The matcher only pairs people from different offices, and the roster is mostly
        // one - so build the pool per office instead of at random, or nobody matches.
        $pool = $everyone->filter(fn (User $u) => $u->location)
            ->groupBy('location')
            ->flatMap(fn ($colleagues) => $colleagues->shuffle()->take(3))
            ->shuffle();

        foreach ($pool as $index => $user) {
            BuddySignup::updateOrCreate(
                ['user_id' => $user->id],
                ['status' => 'waiting', 'note' => $notes[$index % count($notes)]],
            );
        }

        $matcher = app(BuddyMatcher::class);

        foreach ($pool as $user) {
            $matcher->matchFor($user);
        }
    }

    protected function seedOfficeHours($everyone): void
    {
        // What people actually book half an hour with a senior colleague to talk about.
        $topics = [
            'Career paths after four years in the same role',
            'How to say no to a stakeholder without burning the relationship down',
            'Would you look at a deck before I take it to leadership?',
            'Whether to go deeper in my specialism or broaden out from here',
            'Moving from Support into Data — is that realistic?',
            'A teammate who misses every deadline, and what I do about it',
            'What the first ninety days managing a team should look like',
            'Writing for an audience that is not engineers',
            'How you decide what not to work on',
            'Preparing for my first performance conversation as the manager',
            'Making the case for an extra headcount',
            'No agenda, honestly — I just wanted to say hello.',
        ];

        $hosts = $everyone->where('open_to_mentoring', true)->shuffle()->take(6);
        $next = 0;

        foreach ($hosts as $host) {
            foreach ([3, 10] as $daysOut) {
                $slot = OfficeHourSlot::create([
                    'host_id' => $host->id,
                    'title' => 'Office hours with '.explode(' ', $host->name)[0],
                    'description' => 'Anything you like — work, career, or just a hello.',
                    'starts_at' => now()->addDays($daysOut)->setTime(rand(10, 16), 0),
                    'duration_minutes' => 30,
                    'capacity' => rand(1, 3),
                    'location' => $host->location,
                ]);

                $everyone->whereNotIn('id', [$host->id])->random(rand(0, 2))
                    ->each(function (User $u) use ($slot, $topics, &$next) {
                        $slot->bookings()->firstOrCreate(
                            ['user_id' => $u->id],
                            ['topic' => $topics[$next++ % count($topics)]],
                        );
                    });

                $slot->syncBookingsCount();
            }
        }
    }

    protected function seedCoffeeInvites($everyone): void
    {
        $notes = [
            'coffee' => 'Free after standup and in need of a flat white. Anyone want to join?',
            'lunch' => 'Trying the new place downstairs before the queue discovers it.',
            'walk' => 'Need to walk off a long morning. Twenty minutes, nothing strenuous.',
        ];

        $extra = [
            'coffee' => 'Happy to talk shop or not at all. Genuinely do not mind which.',
            'lunch' => 'I will get there at 1 and grab a table. No agenda, just food.',
            'walk' => 'Down to the seafront and back if the weather holds.',
        ];

        foreach (['coffee', 'lunch', 'walk', 'coffee', 'lunch'] as $index => $kind) {
            $host = $everyone->random();

            $invite = CoffeeInvite::create([
                'host_id' => $host->id,
                'kind' => $kind,
                'note' => $index < 3 ? $notes[$kind] : $extra[$kind],
                'starts_at' => now()->addDays($index + 1)->setTime(rand(9, 15), 30),
                'location' => $host->location,
                'capacity' => rand(2, 4),
            ]);

            $everyone->whereNotIn('id', [$host->id])->random(rand(0, 2))
                ->each(fn (User $u) => $invite->joins()->firstOrCreate(['user_id' => $u->id]));

            $invite->syncJoinsCount();
        }
    }

    protected function seedChallenges($everyone, $interests): void
    {
        $challenges = [
            ['October Running Challenge', 'running', 'km', 100, 'Log every kilometre, treadmill counts.'],
            ['Read Three Books', 'reading', 'books', 3, 'Any three books before the month is out.'],
            ['Photo a Day', 'photography', 'photos', 30, 'One photo a day, no filters required.'],
            ['Ten Thousand Steps', 'sports', 'days', 20, 'Twenty days of hitting ten thousand steps.'],
        ];

        // What somebody types into the "note" box when they log a day. Short, and
        // specific to the challenge - a generic line under every entry reads as filler.
        $notes = [
            'running' => [
                'Powai lake loop before work.',
                'Treadmill. Raining again.',
                'Slow one, legs still sore from Sunday.',
                'Marine Drive at 6am. Worth the alarm.',
                'Half of this was walking. Counting it anyway.',
                'Ran to the station and gave up on the train.',
            ],
            'reading' => [
                'Finished it on the train, nearly missed my stop.',
                'Two chapters before bed.',
                'Gave up on this one at page 80. Starting another.',
                'Much better than the cover suggests.',
                'Read the whole thing on a flight.',
            ],
            'photography' => [
                'Golden hour off the balcony.',
                'Street shot near Kala Ghoda.',
                'Phone only today.',
                'Nothing good came out of this one. Posting it regardless.',
                'The neighbour\'s cat, for the fourth time.',
            ],
            'sports' => [
                'Walked to the station and back instead of the rickshaw.',
                'Hit ten thousand by lunch for once.',
                'Airport day. Easy target.',
                'Scraped it at 11pm pacing the living room.',
                'Long walk after dinner, no excuses needed.',
            ],
        ];

        foreach ($challenges as [$title, $category, $unit, $goal, $description]) {
            $creator = $everyone->random();

            $challenge = Challenge::create([
                'created_by' => $creator->id,
                'title' => $title,
                'slug' => Str::slug($title),
                'description' => $description,
                'category' => $category,
                'unit' => $unit,
                'goal_value' => $goal,
                'starts_on' => now()->subDays(10)->toDateString(),
                'ends_on' => now()->addDays(20)->toDateString(),
            ]);

            $participants = $everyone->shuffle()->take(rand(5, 12))->push($creator)->unique('id');

            foreach ($participants as $user) {
                $participant = $challenge->participants()->firstOrCreate(['user_id' => $user->id]);

                foreach (range(1, rand(0, 5)) as $n) {
                    $participant->logs()->create([
                        'value' => rand(1, max(2, (int) ($goal / 4))),
                        'note' => fake()->boolean(40) ? fake()->randomElement($notes[$category]) : null,
                        'logged_on' => now()->subDays(rand(0, 9))->toDateString(),
                    ]);
                }

                $participant->syncTotal();
            }

            $challenge->syncParticipantsCount();
        }
    }

    protected function seedAskRadix($everyone, $skills): void
    {
        // Each question carries the answers it got. A real answer is the whole point
        // of the screen - it is what tells you the pillar works.
        $questions = [
            [
                'Has anyone worked with BigQuery ML?',
                'Trying to decide if it is worth it for a churn model.',
                ['BigQuery', 'Data Modelling'],
                [
                    'We used it for a propensity model last year. For a first churn model it is genuinely good — you get something working in an afternoon without moving data out of the warehouse. Where it fell down for us was iteration: the moment you want custom features and proper cross-validation you end up fighting the SQL. My rule now is BigQuery ML to prove the signal exists, then move it out if it turns out to matter.',
                    'Check your slot usage before you commit to it. Training is billed differently from queries and our first serious run was an unpleasant surprise on the monthly bill. Happy to share the actual numbers if that helps you make the case.',
                ],
                'I have run three of these. Grab thirty minutes and I will save you the first two weeks.',
            ],
            [
                'Anyone travelled to Japan recently?',
                'Two weeks in spring, mostly trains. Any advice welcome.',
                ['Travel'],
                [
                    'Went in April. Two things I would do differently: book the Ghibli Museum the day tickets open rather than the week before, and skip the nationwide rail pass unless you are really covering ground — the maths stopped working for us after the price rise, and regional passes came out cheaper.',
                    'Set up a Suica on your phone before you land and you will never queue at a ticket machine. Also: most of the food worth eating has no English sign and no website. Walk into the small ones with six seats.',
                ],
                'Did three weeks there last year and kept a spreadsheet of everything. Yours if you want it.',
            ],
            [
                'Looking for advice on managing someone for the first time',
                'Starting next month and slightly terrified.',
                ['Managing First Time', 'Hiring'],
                [
                    'The thing nobody told me: your job is now to be boring and predictable. Same one-on-one, same day, same three questions, never cancelled. That reliability builds more trust in the first three months than any clever coaching will.',
                    'Write down what good looks like for them over the first month and actually show it to them. Half the anxiety on both sides is two people quietly guessing where the bar is.',
                ],
                'Made every mistake available in my first year of this. Happy to talk through any of them.',
            ],
            [
                'Best way to run a customer interview?',
                'Never done one properly and I have five booked.',
                ['Customer Interviews'],
                [
                    'Ask about the last time they did the thing, never what they would do in general. "Tell me about the last time you registered a domain" gets you a story with detail in it; "would you use this feature" gets you a polite lie.',
                    'Record them, and do not take notes while they are talking. You will catch three things on the replay that you missed in the room. And stop talking — count to five before you fill a silence, because that is usually when the useful part arrives.',
                ],
                'I can sit in on the first one and take notes so you can just listen. Offer stands.',
            ],
            [
                'Anyone set up Kubernetes autoscaling here?',
                'Specifically the cost side of it.',
                ['Kubernetes'],
                [
                    'We run the cluster autoscaler with HPA on top, but the cost win came almost entirely from right-sizing requests first. Most of our pods were asking for twice what they used, so nothing ever scaled down and the autoscaler had nothing to do. Fix requests before you touch anything else.',
                    'Spot node pools for anything stateless made the single biggest difference to our bill. Set a proper PodDisruptionBudget before you do, or you will find out why on a Tuesday afternoon.',
                ],
                'Happy to walk you through our dashboards and what we actually pay. Easier to show than describe.',
            ],
        ];

        foreach ($questions as [$title, $body, $tagNames, $answers, $volunteerNote]) {
            $asker = $everyone->random();

            $question = RadixQuestion::create([
                'user_id' => $asker->id,
                'title' => $title,
                'body' => $body,
            ]);

            $question->tags()->sync(
                collect($tagNames)->map(fn ($n) => Tag::findOrCreateByName($n, 'skill')->id)->all()
            );

            $helpers = $everyone->whereNotIn('id', [$asker->id])->shuffle()->take(rand(1, 3))->values();

            foreach ($helpers as $index => $helper) {
                // Some people write an answer, others just offer to talk.
                if ($index % 2 === 0) {
                    $question->answers()->create([
                        'user_id' => $helper->id,
                        'body' => $answers[intdiv($index, 2) % count($answers)],
                    ]);
                } else {
                    $question->volunteers()->create([
                        'user_id' => $helper->id,
                        'note' => $volunteerNote,
                    ]);
                }
            }

            $question->syncCounts();
        }
    }

    protected function seedTeachOffers($everyone, $skills): void
    {
        $offers = [
            ['Intro to BigQuery for non-analysts', 'BigQuery', 'session', 'beginner',
                'No SQL needed to start. We go from "what is a table" to you writing your own query against the domains dataset, using the questions people actually ask us in Slack. Bring a laptop and one thing you have been meaning to look up.'],
            ['How we actually price domains', 'Domain Strategy', 'walkthrough', 'any',
                'A walk through the real pricing model — what goes into a premium tier, why two names that look identical can sit ten times apart, and the places we get it wrong. Aimed squarely at people outside the commercial teams who have always quietly wondered.'],
            ['Figma for people who are not designers', 'Figma', 'workshop', 'beginner',
                'Enough Figma to stop screenshotting things badly. Frames, components, and how to leave a comment a designer can actually act on. No design talent required and I promise not to mention the pen tool once.'],
            ['Running a useful retrospective', 'Public Speaking', 'session', 'any',
                'Most retros are a list of complaints plus one action nobody does. Forty-five minutes on structure, facilitation and how to finish with two things that genuinely change. We will run a real one on a recent project rather than a made-up example.'],
        ];

        foreach ($offers as [$title, $topic, $format, $level, $description]) {
            $teacher = $everyone->random();

            $offer = TeachOffer::create([
                'user_id' => $teacher->id,
                'tag_id' => Tag::findOrCreateByName($topic, 'skill')->id,
                'title' => $title,
                'description' => $description,
                'format' => $format,
                'level' => $level,
                'duration_minutes' => 45,
                'min_interested' => 3,
                'preferred_times' => 'Weekday afternoons',
            ]);

            $everyone->whereNotIn('id', [$teacher->id])->random(rand(1, 6))
                ->each(fn (User $u) => $offer->interests()->firstOrCreate(['user_id' => $u->id]));

            $offer->syncInterestedCount();
        }
    }

    protected function seedOpenInvites($everyone): void
    {
        $invites = [
            ['Anyone up for a Sahyadri trek?', 'outdoors', 'Some weekend next month',
                'Nothing fixed yet. Thinking one of the easier ones — Rajmachi or Andharban — so people who have never trekked can come along. Say you are in and I will start a group and we will find a date that works for most of us.'],
            ['Badminton, weekday evenings?', 'sports', 'Whenever we can get a court',
                'Two courts if six of us turn up, one if it is four. Somewhere in Powai or Andheri East, 7pm onwards. All levels genuinely welcome — I am bad at this and intend to stay that way.'],
            ['Board game night at someone\'s place', 'games', 'A Friday, eventually',
                'Catan, Codenames, and Wingspan if anyone has the patience to teach it. My place in Chembur, or somewhere else if that turns out to be closer for more people. I will sort food, bring whatever you want to drink.'],
            ['Photo walk around the old city', 'culture', 'An early Sunday',
                'Start at Kala Ghoda around 7am while the light is still worth having, finish wherever breakfast is. Phone cameras completely welcome — this is not a gear thing and nobody is going to look at your lens.'],
            ['Anyone want to do a book swap?', 'other', 'No rush',
                'Bring two books you are done with, take two you have not read. No genre rules, no obligation to finish anything, no book club afterwards. I will find a table and a corner of the office.'],
        ];

        foreach ($invites as [$title, $category, $timing, $description]) {
            $author = $everyone->random();

            $invite = OpenInvite::create([
                'user_id' => $author->id,
                'title' => $title,
                'description' => $description,
                'category' => $category,
                'rough_timing' => $timing,
            ]);

            $invite->interests()->firstOrCreate(['user_id' => $author->id]);

            $everyone->whereNotIn('id', [$author->id])->random(rand(1, 7))
                ->each(fn (User $u) => $invite->interests()->firstOrCreate(['user_id' => $u->id]));

            $invite->syncInterestedCount();
        }
    }

    /** Phase 2: tag existing stories so interest-led discovery has something to find. */
    protected function tagStories($interests): void
    {
        $map = [
            'sport' => ['Running', 'Cricket'],
            'travel' => ['Travel', 'Trekking', 'Photography'],
            'learning' => ['AI', 'Books'],
            'making' => ['AI', 'Photography'],
            'milestone' => ['Books'],
            'other' => ['Music'],
        ];

        Story::all()->each(function (Story $story) use ($map) {
            $names = $map[$story->category] ?? ['Books'];

            $story->tags()->sync(
                collect($names)->map(fn ($n) => Tag::findOrCreateByName($n, 'interest')->id)->all()
            );
        });
    }

    /**
     * The skills this person's job makes believable, shuffled so two colleagues on
     * the same team don't come out with an identical profile.
     *
     * @param  \Illuminate\Support\Collection<int, Tag>  $skills
     * @return \Illuminate\Support\Collection<int, Tag>
     */
    protected function skillsFor(User $user, $skills)
    {
        $names = self::TEAM_SKILLS[$user->team] ?? self::DEFAULT_SKILLS;

        $title = mb_strtolower((string) $user->job_title);

        foreach (self::MANAGING_RANKS as $rank) {
            if (str_contains($title, $rank)) {
                $names = array_merge(['Hiring', 'Managing First Time'], $names);

                break;
            }
        }

        return $skills->filter(fn (Tag $tag) => in_array($tag->name, $names, true))->shuffle()->values();
    }

    /**
     * Puts a tag on one of the four profile sections.
     *
     * attach() guarded by an existence check, not syncWithoutDetaching(): sync keys
     * on the tag id alone, so re-attaching a skill under a second kind updates the
     * existing row instead of adding one - which both loses the first section and
     * collides with the (user, tag, kind) unique index. A skill being both "can talk
     * about" and "can help with" is the normal case, the same way SkillSeeder writes
     * it for the people the export covers.
     */
    protected function attach(User $user, $tags, string $kind): void
    {
        foreach (collect($tags) as $tag) {
            $already = $user->tags()->wherePivot('kind', $kind)->where('tags.id', $tag->id)->exists();

            if (! $already) {
                $user->tags()->attach($tag->id, ['kind' => $kind]);
            }
        }
    }

    protected function seedMeetups($everyone): void
    {
        // Last month's round, already matched, plus this month's round taking signups.
        $previous = now()->subMonth();
        $past = MeetupRound::create([
            'title' => 'Blind Meetups · '.$previous->format('F Y'),
            'period' => $previous->format('Y-m'),
            'signups_open_at' => $previous->copy()->startOfMonth(),
            'signups_close_at' => $previous->copy()->startOfMonth()->addDays(20),
            'meetup_date' => BlindMeetupMatcher::lastFridayOf($previous->year, $previous->month),
            'status' => 'open',
        ]);

        $everyone->where('open_to_blind_meetups', true)->shuffle()->take(18)
            ->each(fn (User $u) => $past->signups()->create(['user_id' => $u->id]));

        app(BlindMeetupMatcher::class)->match($past);

        $current = now();
        $round = MeetupRound::create([
            'title' => 'Blind Meetups · '.$current->format('F Y'),
            'period' => $current->format('Y-m'),
            'signups_open_at' => $current->copy()->startOfMonth(),
            'signups_close_at' => $current->copy()->endOfMonth()->subDays(5),
            'meetup_date' => BlindMeetupMatcher::lastFridayOf($current->year, $current->month),
            'status' => 'open',
        ]);

        $everyone->where('open_to_blind_meetups', true)->shuffle()->take(9)
            ->each(fn (User $u) => $round->signups()->create(['user_id' => $u->id]));
    }

    protected function seedSessions($everyone): void
    {
        // The message is the part a recipient reads before deciding, so each one says
        // why this person, rather than the same polite sentence six times over.
        $topics = [
            ['Getting started with BigQuery ML', 'knowledge', 'technical',
                'Saw BigQuery on your profile. I am trying to work out whether to build a churn model in the warehouse or do it properly, and half an hour with someone who has already done it would save me a fortnight of guessing.'],
            ['Moving from IC to managing a team', 'mentoring', 'leadership',
                'I have been offered a team next quarter and I am genuinely unsure I want it. Would really value thirty minutes with someone who has made that jump and can tell me what it actually costs.'],
            ['How you think about domain pricing', 'knowledge', 'work_knowledge',
                'I sit well outside the commercial side and I have been nodding along in meetings for about a year now. Would love the actual mental model rather than continuing to fake it.'],
            ['Career paths outside the obvious ladder', 'mentoring', 'career',
                'Four years in and I have realised I do not want my manager\'s job. Curious how you thought about it when you were at roughly this point.'],
            ['Handling a difficult 1:1', 'coaching', 'people',
                'I have one coming up that I have now postponed twice. Would much rather rehearse it with someone than walk in and improvise.'],
            ['What ten years at Radix taught you', 'knowledge', 'personal_experience',
                'No real agenda beyond wanting to hear it. Your name comes up every time somebody explains why we do something a particular way.'],
        ];

        foreach ($topics as [$topic, $kind, $category, $message]) {
            $pair = $everyone->where('open_to_mentoring', true)->random(2)->values();

            SessionRequest::create([
                'requester_id' => $pair[0]->id,
                'recipient_id' => $pair[1]->id,
                'kind' => $kind,
                'topic' => $topic,
                'category' => $category,
                'message' => $message,
                'duration_minutes' => $kind === 'coaching' ? 45 : 30,
                'status' => fake()->randomElement(['pending', 'pending', 'accepted', 'declined']),
                'scheduled_at' => fake()->boolean(50) ? now()->addDays(rand(2, 12)) : null,
            ]);
        }
    }


    /**
     * Gives the admin account something of their own on the Connect pillars.
     *
     * Not favouritism - it is the account every demo starts on, so a blank bell
     * and an empty "things you host" is the first thing anyone sees. Each row is
     * real activity with real participants, so NotificationSeeder then fills the
     * inbox from it exactly as it does for everybody else.
     */
    protected function seedDemoAccountActivity($everyone): void
    {
        $admin = $everyone->firstWhere('role', 'admin');

        if (! $admin) {
            return;
        }

        $others = $everyone->whereNotIn('id', [$admin->id]);

        // Nothing here touches Groups, Events, Recommendations or After Hrs: those
        // four tabs belong to the design canvas, down to the row, and a group or
        // a story invented for the admin would be the one thing on that page
        // nobody drew. The admin gets the Connect-side activity instead, which
        // the canvas leaves to us.

        $slot = OfficeHourSlot::create([
            'host_id' => $admin->id,
            'title' => 'Office hours with '.explode(' ', $admin->name)[0],
            'description' => 'Backends, hiring, or how any part of Radix Connect works. Bring a question or do not.',
            'starts_at' => now()->addDays(4)->setTime(15, 0),
            'duration_minutes' => 30,
            'capacity' => 3,
            'location' => $admin->location,
        ]);
        foreach ([
            'How do I get started contributing to the backend?',
            'Career advice — I want to move towards infrastructure.',
        ] as $index => $topic) {
            $slot->bookings()->firstOrCreate(
                ['user_id' => $others->values()[$index * 7]->id],
                ['topic' => $topic],
            );
        }
        $slot->syncBookingsCount();

        $coffee = CoffeeInvite::create([
            'host_id' => $admin->id,
            'kind' => 'coffee',
            'note' => 'Downstairs at 4, anyone who wants a break from their screen. I am buying.',
            'starts_at' => now()->addDays(2)->setTime(16, 0),
            'location' => $admin->location,
            'capacity' => 4,
        ]);
        $others->random(3)->each(fn (User $u) => $coffee->joins()->firstOrCreate(['user_id' => $u->id]));
        $coffee->syncJoinsCount();

        $offer = TeachOffer::create([
            'user_id' => $admin->id,
            'tag_id' => Tag::findOrCreateByName('Laravel', 'skill')->id,
            'title' => 'Reading a Laravel codebase you did not write',
            'description' => 'Where to start when you open an unfamiliar Laravel project — routes, then the models, then the tests, and why that order. We will do it live on this one.',
            'format' => 'session',
            'level' => 'beginner',
            'duration_minutes' => 45,
            'min_interested' => 3,
            'preferred_times' => 'Weekday afternoons',
        ]);
        $others->random(5)->each(fn (User $u) => $offer->interests()->firstOrCreate(['user_id' => $u->id]));
        $offer->syncInterestedCount();

        $invite = OpenInvite::create([
            'user_id' => $admin->id,
            'title' => 'Long walk, Bandra to Worli, whenever',
            'description' => 'About twelve kilometres along the sea road, taken slowly, with a stop for breakfast roughly in the middle. No fitness required and nobody is timing it.',
            'category' => 'outdoors',
            'rough_timing' => 'A Sunday morning, once it cools down',
        ]);
        $invite->interests()->firstOrCreate(['user_id' => $admin->id]);
        $others->random(6)->each(fn (User $u) => $invite->interests()->firstOrCreate(['user_id' => $u->id]));
        $invite->syncInterestedCount();

        // Both directions, so the mentoring tab has an incoming and an outgoing row.
        $askers = $others->where('open_to_mentoring', true)->random(2)->values();

        SessionRequest::create([
            'requester_id' => $askers[0]->id,
            'recipient_id' => $admin->id,
            'kind' => 'mentoring',
            'topic' => 'Getting into backend engineering from support',
            'category' => 'career',
            'message' => 'I have been on the support side for three years and I write small scripts for myself constantly. Would love half an hour on whether that is a realistic move and what I would need to learn first.',
            'status' => 'pending',
        ]);

        SessionRequest::create([
            'requester_id' => $admin->id,
            'recipient_id' => $askers[1]->id,
            'kind' => 'knowledge',
            'topic' => 'How the commercial side reads our roadmap',
            'category' => 'work_knowledge',
            'message' => 'We keep building things the commercial teams then have to explain, and I suspect that is our fault rather than theirs. Would like thirty minutes to hear it from your side.',
            'status' => 'accepted',
            'scheduled_at' => now()->addDays(6)->setTime(11, 30),
        ]);
    }
}
