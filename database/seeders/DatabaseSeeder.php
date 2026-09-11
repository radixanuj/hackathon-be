<?php

namespace Database\Seeders;

use App\Models\Ama;
use App\Models\Event;
use App\Models\InterestGroup;
use App\Models\MeetupRound;
use App\Models\Recommendation;
use App\Models\SessionRequest;
use App\Models\Story;
use App\Models\Tag;
use App\Models\User;
use App\Services\BlindMeetupMatcher;
use App\Services\QuestBuilder;
use Database\Factories\UserFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/** Seeds a believable Radix for demoing every Phase 1 pillar. */
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

    public function run(): void
    {
        $skills = collect(self::SKILLS)->map(fn ($n) => Tag::findOrCreateByName($n, 'skill'));
        $interests = collect(self::INTERESTS)->map(fn ($n) => Tag::findOrCreateByName($n, 'interest'));

        $admin = User::factory()->admin()->veteran()->create([
            'name' => 'Anuj Maurya',
            'email' => 'admin@radix.email',
            'team' => 'Engineering',
            'location' => 'Mumbai',
            'intro' => 'Building Radix Connect. Ask me about backends, hiring and long treks.',
        ]);

        $veterans = User::factory()->count(12)->veteran()->create();
        $regulars = User::factory()->count(20)->create();
        $newJoiners = User::factory()->count(5)->newJoiner()->create();

        $everyone = collect([$admin])->concat($veterans)->concat($regulars)->concat($newJoiners);

        // Profiles: the four sections that answer "why talk to this person?"
        $everyone->each(function (User $user) use ($skills, $interests) {
            $this->attach($user, $skills->random(3), 'can_talk_about');
            $this->attach($user, $skills->random(2), 'can_help_with');
            $this->attach($user, $skills->random(2), 'want_to_learn');
            $this->attach($user, $interests->random(3), 'interest');
        });

        Tag::all()->each(fn (Tag $tag) => $tag->update(['usage_count' => $tag->users()->count()]));

        // New Joiner Quest for everyone who just arrived.
        $builder = app(QuestBuilder::class);
        $newJoiners->each(fn (User $user) => $builder->buildFor($user));

        $this->seedMeetups($everyone);
        $this->seedSessions($everyone);
        $this->seedGroups($everyone);
        $this->seedRecommendations($everyone);
        $this->seedAmasAndStories($everyone);
        $this->seedEvents($everyone);
    }

    protected function attach(User $user, $tags, string $kind): void
    {
        foreach (collect($tags) as $tag) {
            $user->tags()->syncWithoutDetaching([$tag->id => ['kind' => $kind]]);
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
        $topics = [
            ['Getting started with BigQuery ML', 'technical'],
            ['Moving from IC to managing a team', 'leadership'],
            ['How you think about domain pricing', 'work_knowledge'],
            ['Career paths outside the obvious ladder', 'career'],
            ['Handling a difficult 1:1', 'people'],
            ['What ten years at Radix taught you', 'personal_experience'],
        ];

        foreach ($topics as [$topic, $category]) {
            $pair = $everyone->where('open_to_mentoring', true)->random(2)->values();

            SessionRequest::create([
                'requester_id' => $pair[0]->id,
                'recipient_id' => $pair[1]->id,
                'topic' => $topic,
                'category' => $category,
                'message' => 'Saw this on your profile - would love 30 minutes if you can spare it.',
                'status' => fake()->randomElement(['pending', 'pending', 'accepted', 'declined']),
                'scheduled_at' => fake()->boolean(50) ? now()->addDays(rand(2, 12)) : null,
            ]);
        }
    }

    protected function seedGroups($everyone): void
    {
        $groups = [
            ['F1 Sundays', 'sports', '🏎️', 'Race weekends, hot takes and the occasional watch party.'],
            ['Radix Runners', 'sports', '🏃', 'Weekend runs, race plans and a lot of shoe talk.'],
            ['Book Club', 'books', '📚', 'One book a month, no pressure to finish it.'],
            ['Trekking Crew', 'outdoors', '⛰️', 'Sahyadri weekends and the occasional Himalayan plan.'],
            ['AI Tinkerers', 'tech', '🤖', 'What we are building, breaking and reading this week.'],
            ['Frame by Frame', 'film', '🎬', 'Films worth arguing about.'],
            ['Shutterbugs', 'other', '📷', 'Photo walks and camera envy.'],
            ['Board Game Nights', 'games', '🎲', 'Catan, Codenames and a rotating host.'],
        ];

        foreach ($groups as [$name, $category, $emoji, $description]) {
            $creator = $everyone->random();

            $group = InterestGroup::create([
                'name' => $name,
                'slug' => Str::slug($name),
                'description' => $description,
                'category' => $category,
                'emoji' => $emoji,
                'external_platform' => fake()->randomElement(['whatsapp', 'slack']),
                'external_link' => 'https://example.com/'.Str::slug($name),
                'created_by' => $creator->id,
            ]);

            $group->members()->attach($creator->id, ['role' => 'owner']);

            $everyone->whereNotIn('id', [$creator->id])->random(rand(4, 12))
                ->each(fn (User $u) => $group->members()->syncWithoutDetaching([$u->id => ['role' => 'member']]));

            $group->syncMembersCount();
        }
    }

    protected function seedRecommendations($everyone): void
    {
        $items = [
            ['The Manager\'s Path', 'Camille Fournier', 'book', 'work'],
            ['Acquired', 'Ben Gilbert & David Rosenthal', 'podcast', 'work'],
            ['Shape Up', 'Ryan Singer', 'book', 'work'],
            ['Designing Data-Intensive Applications', 'Martin Kleppmann', 'book', 'work'],
            ['Linear', null, 'tool', 'work'],
            ['Drive to Survive', null, 'show', 'leisure'],
            ['Everything Everywhere All At Once', null, 'film', 'leisure'],
            ['Project Hail Mary', 'Andy Weir', 'book', 'leisure'],
            ['Darknet Diaries', 'Jack Rhysider', 'podcast', 'leisure'],
            ['The Bear', null, 'show', 'leisure'],
        ];

        foreach ($items as [$title, $creator, $type, $stream]) {
            Recommendation::create([
                'user_id' => $everyone->random()->id,
                'title' => $title,
                'creator' => $creator,
                'type' => $type,
                'stream' => $stream,
                'why' => fake()->sentence(16),
            ]);
        }
    }

    protected function seedAmasAndStories($everyone): void
    {
        $stories = [
            ['Finished my first HYROX', 'sport'],
            ['Ten years at Radix, and what changed', 'milestone'],
            ['Three weeks solo across Japan', 'travel'],
            ['Built a mechanical keyboard from scratch', 'making'],
            ['Learned to sail this summer', 'learning'],
            ['Kedarkantha in January, in the snow', 'travel'],
        ];

        foreach ($stories as [$title, $category]) {
            $author = $everyone->random();

            $story = Story::create([
                'user_id' => $author->id,
                'title' => $title,
                'body' => fake()->paragraphs(3, true),
                'category' => $category,
            ]);

            $everyone->random(rand(3, 10))->each(fn (User $u) => $story->reactions()->firstOrCreate(
                ['user_id' => $u->id],
                ['reaction' => fake()->randomElement(['clap', 'heart', 'mind_blown', 'inspired'])],
            ));

            $story->syncReactionsCount();
        }

        // A couple of stories naturally became AMAs.
        Story::query()->limit(2)->get()->each(function (Story $story) use ($everyone) {
            $ama = Ama::create([
                'host_id' => $story->user_id,
                'title' => 'AMA: '.$story->title,
                'description' => $story->body,
                'format' => 'async',
                'status' => 'open',
                'opens_at' => now(),
                'story_id' => $story->id,
            ]);

            $story->update(['ama_id' => $ama->id]);

            $everyone->random(4)->each(function (User $u) use ($ama) {
                $ama->questions()->create([
                    'user_id' => $u->id,
                    'body' => fake()->sentence(12).'?',
                ]);
            });

            $ama->syncQuestionsCount();
        });
    }

    protected function seedEvents($everyone): void
    {
        $events = [
            ['Sunday morning run at Powai Lake', 'sports', '+4 days'],
            ['F1 screening: Monza', 'film', '+9 days'],
            ['Trek to Rajmachi', 'outdoors', '+16 days'],
            ['Team dinner at Bandra', 'food', '+6 days'],
            ['Virtual game night', 'games', '+11 days'],
            ['Workshop: writing better docs', 'work', '+14 days'],
            ['Museum walk: CSMVS', 'culture', '+21 days'],
        ];

        foreach ($events as [$title, $category, $when]) {
            $host = $everyone->random();

            $event = Event::create([
                'host_id' => $host->id,
                'title' => $title,
                'description' => fake()->sentence(18),
                'category' => $category,
                'starts_at' => now()->modify($when)->setTime(rand(7, 19), 0),
                'location' => fake()->randomElement(UserFactory::LOCATIONS),
                'is_virtual' => $category === 'games',
                'capacity' => fake()->randomElement([null, 10, 20]),
            ]);

            $event->rsvps()->create(['user_id' => $host->id, 'status' => 'going']);

            $everyone->whereNotIn('id', [$host->id])->random(rand(3, 9))
                ->each(fn (User $u) => $event->rsvps()->firstOrCreate(
                    ['user_id' => $u->id],
                    ['status' => fake()->randomElement(['going', 'going', 'maybe'])],
                ));

            $event->syncGoingCount();
        }
    }
}
