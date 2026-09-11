<?php

namespace Database\Seeders;

use App\Models\Ama;
use App\Models\Event;
use App\Models\InterestGroup;
use App\Models\Recommendation;
use App\Models\Story;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Find Your Crowd, exactly as the design canvas draws it.
 *
 * The four tabs on that page - Groups, Events, Recommendations and After Hrs -
 * are the one place where the canvas is the specification rather than a sketch:
 * every group, event, recommendation, AMA and story below is transcribed from
 * it, and nothing that is not on it is seeded. If a row disappears from the
 * canvas it should disappear from here, and the page should never show content
 * that somebody has to explain away as "just seed data" during a demo.
 *
 * Two things the canvas cannot supply are resolved here rather than invented:
 *
 *  - Its cast is a placeholder roster (Mateus Tavares, Mei Chen, Priya Menon).
 *    Ours is the real org chart, so CANVAS_PEOPLE maps each placeholder onto
 *    the colleague they stand in for. Where the canvas already names a real
 *    Radix person - every recommender, every storyteller - the mapping is the
 *    identity and only the spelling is corrected.
 *  - Its counts ("24 members", "15 interested") are numbers on a card with no
 *    names behind them. The named crew joins first and the remainder is filled
 *    from the roster in a fixed rotation, so the counts match the canvas and
 *    two runs of the seeder produce the same rooms.
 */
class CrowdSeeder extends Seeder
{
    /**
     * The canvas cast, mapped onto the people we actually have.
     *
     * Matched on who the placeholder is standing in for rather than on the name:
     * the canvas's nine-year engineer becomes the engineer nine years in, its
     * HYROX finisher becomes the colleague whose HYROX photograph the frontend
     * already ships. The three real-but-misspelt names are corrected in place.
     */
    protected const CANVAS_PEOPLE = [
        'Parag Barhate' => 'Parag Barhate',
        'Sahar Khan' => 'Sahar Khan',
        'Rushabh Vohra' => 'Rushabh Vora',
        'Shivanshu Srivastava' => 'Shivanshu Shrivastava',
        'Swati M' => 'Swati Maheshwari',
        'Devyani Beohar' => 'Devyani Beohar',
        'Daniel Visser' => 'Bhavesh Bhide',          // Engineering, nine years in
        'Ananya Deshpande' => 'Tanisha Singh',       // the HYROX finisher
        'Mateus Tavares' => 'Alessandro Dsouza',     // Engineering
        'Mei Chen' => 'Manbir Chawla',               // shares the canvas initials
        'Arjun Rao' => 'Arjun Pande',
        'Amrita Iyer' => 'Vishal Pansari',           // Finance
        'Priya Menon' => 'Neha Vijay Nair',          // the closest thing we have to Legal
        'Vikram Nair' => 'Minita Sodhi',             // People
        'Sarah Okoye' => 'Priyanka Panchmatia',      // Brand
    ];

    /**
     * The avatar initials on the canvas's event cards.
     *
     * Fourteen of the seventeen are a real colleague's initials and resolve to
     * them directly. The three that are not - VN, AI, DV - belong to placeholder
     * people, and take the same stand-in their team gets everywhere else.
     */
    protected const CANVAS_INITIALS = [
        'KJ' => 'Karn Jajoo',
        'SR' => 'Sandeep Ramchandani',
        'NN' => 'Neha Naik',
        'AV' => 'Arjava Vig',
        'AS' => 'Arun Seshadri',
        'RV' => 'Rushabh Vora',
        'PB' => 'Parag Barhate',
        'PS' => 'Prakash Shahani',
        'SK' => 'Sahar Khan',
        'TS' => 'Tanisha Singh',
        'MC' => 'Manbir Chawla',
        'VL' => 'Viram Lodhia',
        'AR' => 'Anita Ramachandran',
        'AD' => 'Ankit Dembla',
        'VN' => 'Minita Sodhi',      // Vikram Nair, People
        'AI' => 'Vishal Pansari',    // Amrita Iyer, Finance
        'DV' => 'Bhavesh Bhide',     // Daniel Visser, Engineering
    ];

    /** Groups tab: name, emoji, category, description, members, owner, named crew. */
    protected const GROUPS = [
        ['F1 Fans', '🏎', 'sports', 'Race-day threads, strategy arguments, and an occasional screening in Dubai.',
            24, 'Mateus Tavares', ['Sahar Khan', 'Vikram Nair', 'Amrita Iyer', 'Parag Barhate']],
        ['Books', '📚', 'books', 'One book a month. Nobody finishes it. We meet anyway.',
            14, 'Priya Menon', ['Rushabh Vohra', 'Sarah Okoye', 'Daniel Visser']],
        ['Trekking', '🥾', 'outdoors', 'Weekend walks, route planning, and gear we didn\'t need to buy.',
            9, 'Arjun Rao', ['Rushabh Vohra', 'Sahar Khan', 'Parag Barhate']],
        ['Cricket', '🏏', 'sports', 'Match threads across four time zones, plus a Mumbai net session.',
            18, 'Amrita Iyer', ['Parag Barhate', 'Vikram Nair', 'Sahar Khan', 'Mei Chen']],
        ['Gaming', '🎮', 'games', 'Co-op nights that start late and end later.',
            11, 'Mei Chen', ['Ananya Deshpande', 'Mateus Tavares']],
        ['Sourdough', '🥖', 'food', 'Starter photos. So many starter photos.',
            6, 'Mei Chen', ['Sarah Okoye', 'Priya Menon']],
    ];

    /**
     * Events tab: title, category, date, location, interested, the avatars shown.
     *
     * The canvas gives a date and no time, so everything starts at 6:30pm - the
     * hour all of these would actually happen at - and no event carries a
     * description, because the canvas does not write one.
     */
    protected const EVENTS = [
        ['Book clubs', 'culture', '2026-09-18', 'Mumbai', 15, ['KJ', 'SR', 'NN', 'AV']],
        ['Squash sessions', 'sports', '2026-09-20', 'Dubai', 7, ['AS', 'RV', 'VN']],
        ['Open hackathons', 'work', '2026-09-21', 'Dubai', 13, ['PB', 'KJ', 'AV', 'MC']],
        ['Art galleries', 'culture', '2026-09-25', 'Mumbai', 5, ['AV', 'NN', 'KJ']],
        ['Technical events', 'work', '2026-09-27', 'Dubai', 11, ['PB', 'PS', 'KJ', 'AV']],
        ['Movie marathons', 'film', '2026-09-28', 'Mumbai', 9, ['SK', 'NN', 'TS', 'MC']],
        ['Cricket', 'sports', '2026-10-03', 'Mumbai', 18, ['AI', 'PB', 'VL', 'AS']],
        ['Pickle ball', 'sports', '2026-10-04', 'Dubai', 8, ['RV', 'DV', 'SR']],
        ['Cycling', 'sports', '2026-10-05', 'Dubai', 6, ['VN', 'AR', 'TS']],
        ['Gaming sessions', 'games', '2026-10-09', 'Mumbai', 12, ['MC', 'DV', 'KJ', 'PS']],
        ['Treks', 'outdoors', '2026-10-11', 'Mumbai', 10, ['AR', 'AD', 'TS', 'MC']],
        ['Chill sessions', 'other', '2026-10-15', 'Dubai', 14, ['NN', 'SR', 'AV', 'VL']],
        ['Grab a drink after work', 'food', '2026-10-17', 'Mumbai', 16, ['VL', 'RV', 'SK', 'PS']],
        ['Food walks & festivities', 'food', '2026-10-23', 'Mumbai', 22, ['NN', 'TS', 'AI', 'SR']],
        ['Zomato recommendations', 'food', '2026-10-24', 'Dubai', 17, ['SK', 'VL', 'DV', 'PS']],
        ['Hikes', 'outdoors', '2026-10-26', 'Dubai', 9, ['AR', 'TS', 'MC']],
        ['Tennis', 'sports', '2026-10-29', 'Mumbai', 7, ['RV', 'VN', 'AS']],
        ['Paddle ball', 'sports', '2026-10-31', 'Dubai', 6, ['DV', 'SR', 'KJ']],
    ];

    /**
     * Recommendations tab: type, title, who recommended it, why.
     *
     * Every name here is already a real colleague - this list came off the
     * canvas as it came off the company. The canvas names no authors, so no
     * recommendation carries a creator, and it shows no like counts, so none
     * are seeded.
     */
    protected const RECOMMENDATIONS = [
        'work' => [
            ['resource', 'growth.design', 'Arjava Vig',
                'Visual case studies that unpack product decisions through design thinking and customer empathy.'],
            ['resource', 'Mental Models', 'Arjava Vig',
                'A practical collection of frameworks for seeing problems differently and making sharper decisions.'],
            ['book', 'Atomic Habits', 'Arun Seshadri',
                'Small changes. Smart systems. A practical playbook for making better habits stick.'],
            ['book', 'Do Epic Shit', 'Arun Seshadri',
                'Straight-talking lessons on ambition, choices and building a life on your own terms.'],
            ['book', 'Grit', 'Arun Seshadri',
                'Why talent only gets you so far—and perseverance often takes you the rest of the way.'],
            ['book', 'Hidden Potential', 'Arun Seshadri',
                'A fresh look at how people improve, unlock overlooked strengths and achieve more than expected.'],
            ['book', 'Start With Why', 'Arun Seshadri',
                'A powerful case for putting purpose at the heart of leadership, communication and action.'],
            ['book', 'Read Write Own', 'Karn Jajoo',
                'A big-picture argument for how blockchain could reshape ownership, creativity and power online.'],
            ['podcast', 'Dwarkesh Podcast', 'Karn Jajoo',
                'Long, fearless conversations with people working on some of the world’s biggest ideas.'],
            ['podcast', 'Invest Like the Best', 'Karn Jajoo',
                'Deep conversations about investing, business building and the ideas driving both.'],
            ['person', 'Aakash Gupta', 'Karn Jajoo',
                'Product and growth ideas worth adding to your feed.'],
            ['person', 'Justin Skycak', 'Karn Jajoo',
                'Clear thinking on learning, technology and solving difficult problems.'],
            ['person', 'Paras Chopra', 'Karn Jajoo',
                'Sharp perspectives on products, experimentation and entrepreneurship.'],
            ['podcast', 'Acquired', 'Neha Naik',
                'The blockbuster stories behind the companies that changed business—and how they pulled it off.'],
            ['podcast', 'Lenny’s Podcast', 'Neha Naik',
                'Practical lessons on building products, growing companies and leading teams.'],
            ['book', 'Flow: The Psychology of Optimal Experience', 'Parag Barhate',
                'The science behind getting completely absorbed in challenging, meaningful work.'],
            ['podcast', 'AI Daily Brief', 'Parag Barhate',
                'A fast, focused guide to what is happening in AI—and why it matters.'],
            ['podcast', 'Better Offline', 'Prakash Shahani',
                'A sharp critique of the technology industry, its power players and its broken promises.'],
            ['newsletter', 'Computer Says Maybe', 'Prakash Shahani',
                'A clear-eyed look at technology, automated systems and the uncertainty hiding behind confident answers.'],
            ['podcast', 'How I Built This', 'Prakash Shahani',
                'The messy, surprising stories behind some of the world’s best-known companies.'],
            ['podcast', 'Killswitch', 'Prakash Shahani',
                'A close look at technology, power and the systems quietly shaping modern life.'],
            ['podcast', 'Masters of Scale', 'Prakash Shahani',
                'Big lessons from leaders who turned ambitious ideas into companies built to grow.'],
            ['podcast', 'Pivot', 'Prakash Shahani',
                'A rapid-fire take on the biggest clashes, characters and shake-ups in tech and business.'],
            ['podcast', 'Tech Won’t Save Us', 'Prakash Shahani',
                'A critical look at the technology industry—and the belief that every problem needs a digital fix.'],
            ['podcast', 'TechStuff', 'Prakash Shahani',
                'The stories, systems and inventions behind the technology surrounding us.'],
            ['podcast', 'The AI Breakdown', 'Prakash Shahani',
                'The day’s biggest AI developments, stripped of the noise and put into context.'],
            ['podcast', 'Uncanny Valley', 'Prakash Shahani',
                'A revealing look inside Silicon Valley’s ideas, influence and contradictions.'],
            ['podcast', 'All-In Podcast', 'Sandeep Ramchandani',
                'Four insiders battle over technology, markets, politics and the week’s biggest bets.'],
            ['podcast', 'Lenny’s Podcast', 'Sandeep Ramchandani',
                'Useful, no-fluff conversations about products, growth and building great teams.'],
            ['podcast', 'The Naval Podcast', 'Sandeep Ramchandani',
                'Compact ideas about wealth, judgment, happiness and thinking for yourself.'],
            ['podcast', 'The Prof G Pod', 'Sandeep Ramchandani',
                'Bold takes on business, technology and the forces moving markets.'],
            ['book', 'Obviously Awesome', 'Sandeep Ramchandani',
                'A practical guide to positioning products so customers instantly understand why they matter.'],
            ['book', 'Antifragile', 'Viram Lodhia',
                'Why the strongest systems do more than survive disorder—they improve because of it.'],
            ['book', 'Blue Ocean Strategy', 'Viram Lodhia',
                'A framework for escaping crowded markets and creating space where the competition matters less.'],
            ['book', 'Never Split the Difference', 'Viram Lodhia',
                'High-stakes negotiation tactics made practical for everyday conversations.'],
            ['book', 'Thinking, Fast and Slow', 'Viram Lodhia',
                'A landmark tour of the shortcuts, biases and hidden machinery behind human judgment.'],
            ['book', 'The Almanack of Naval Ravikant', 'Viram Lodhia',
                'A concentrated collection of ideas about wealth, judgment, freedom and happiness.'],
            ['book', 'Zero to One', 'Viram Lodhia',
                'A provocative guide to building something genuinely new instead of copying what already works.'],
            ['podcast', 'Acquired', 'Viram Lodhia',
                'Epic deep dives into the deals, decisions and drama behind legendary companies.'],
            ['podcast', 'Diary of a CEO', 'Viram Lodhia',
                'Candid conversations about ambition, leadership, setbacks and success.'],
            ['podcast', 'How I Built This', 'Viram Lodhia',
                'Founders reveal the breakthroughs, wrong turns and lucky breaks behind their businesses.'],
        ],
        'leisure' => [
            ['book', 'The Subtle Art of Not Giving a F*ck', 'Arun Seshadri',
                'A blunt, funny case for caring less about everything—and more about what truly matters.'],
            ['book', 'The Lessons of History', 'Karn Jajoo',
                'A brisk, big-picture look at what centuries of civilization reveal about power, conflict and human nature.'],
            ['book', 'The Molecule of More', 'Karn Jajoo',
                'How one brain chemical fuels desire, ambition, imagination—and our endless chase for what comes next.'],
            ['book', 'The Untethered Soul', 'Sandeep Ramchandani',
                'An invitation to step back from the noise in your head and find greater clarity within.'],
        ],
    ];

    /** Recommendations tab, lower half: the three AMAs the canvas lists. */
    protected const AMAS = [
        ['Ask me about running my first marathon', 'live', 'Sahar Khan'],
        ['Nine years at Radix. Ask me anything.', 'async', 'Daniel Visser'],
        ['From zero to HYROX in eight months', 'async', 'Ananya Deshpande'],
    ];

    /** After Hrs tab: title, category, who, the line under it, photo, reactions. */
    protected const STORIES = [
        // Leads the tab: `discover` falls through to reactions_count once tag
        // matching ties, so the most-reacted story is the one that gets the slab.
        ['Monsoon in Maharashtra is a vibe like no other!', 'travel', 'Devyani Beohar',
            '', '/photos/monsoon.jpg', 61],
        // Filed under travel rather than other: the canvas's own line puts it on a
        // hike, and "other" is the category that tags stories as Music.
        ['A crab dragging away its lunch (a snake)', 'travel', 'Shivanshu Srivastava',
            'Spotted during a hike in the National Park.', '/photos/crab.jpg', 52],
        ['A week in Gurez Valley, Kashmir', 'travel', 'Swati M',
            'Village hopping near the LoC was not on my bucket list, but here I was.', '/photos/gurez.jpg', 38],
    ];

    /** The roster, in a fixed order, so the fill below is the same on every run. */
    protected Collection $roster;

    public function run(): void
    {
        $this->roster = User::query()->orderBy('id')->get();

        $this->seedGroups();
        $this->seedEvents();
        $this->seedRecommendations();
        $this->seedAmas();
        $this->seedStories();
    }

    protected function seedGroups(): void
    {
        foreach (self::GROUPS as $index => [$name, $emoji, $category, $description, $members, $owner, $crew]) {
            $slug = Str::slug($name);

            $group = InterestGroup::create([
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'category' => $category,
                'emoji' => $emoji,
                // The canvas's join confirmation promises a WhatsApp link on the
                // group page, so every group has one - shaped like the real thing
                // and derived from the slug rather than randomised.
                'external_platform' => 'whatsapp',
                'external_link' => 'https://chat.whatsapp.com/'.Str::upper(substr(md5($slug), 0, 22)),
                'created_by' => $this->person($owner)->id,
            ]);

            $named = collect([$owner])->merge($crew)->map(fn (string $n) => $this->person($n));

            $group->members()->attach($named->first()->id, ['role' => 'owner']);

            $this->roomOf($named, $members, $index)->skip(1)
                ->each(fn (User $u) => $group->members()->syncWithoutDetaching([$u->id => ['role' => 'member']]));

            $group->syncMembersCount();
        }
    }

    protected function seedEvents(): void
    {
        foreach (self::EVENTS as $index => [$title, $category, $date, $location, $interested, $initials]) {
            $named = collect($initials)->map(fn (string $i) => $this->personByInitials($i));
            $host = $named->first();

            $event = Event::create([
                'host_id' => $host->id,
                'title' => $title,
                'category' => $category,
                'starts_at' => Str::of($date)->append(' 18:30:00')->toString(),
                'location' => $location,
            ]);

            $this->roomOf($named, $interested, $index)
                ->each(fn (User $u) => $event->rsvps()->firstOrCreate(['user_id' => $u->id], ['status' => 'going']));

            $event->syncGoingCount();
        }
    }

    protected function seedRecommendations(): void
    {
        foreach (self::RECOMMENDATIONS as $stream => $items) {
            foreach ($items as [$type, $title, $by, $why]) {
                Recommendation::create([
                    'user_id' => $this->person($by)->id,
                    'title' => $title,
                    'type' => $type,
                    'stream' => $stream,
                    'why' => $why,
                ]);
            }
        }
    }

    protected function seedAmas(): void
    {
        foreach (self::AMAS as [$title, $format, $host]) {
            Ama::create([
                'host_id' => $this->person($host)->id,
                'title' => $title,
                'format' => $format,
                'status' => 'open',
                // "Async · open all week" and "Thursday · 4 PM", as the cards read.
                'opens_at' => now()->startOfWeek(),
                'closes_at' => $format === 'async' ? now()->endOfWeek() : null,
                'scheduled_at' => $format === 'live' ? now()->next('Thursday')->setTime(16, 0) : null,
            ]);
        }
    }

    protected function seedStories(): void
    {
        foreach (self::STORIES as $index => [$title, $category, $who, $body, $photo, $reactions]) {
            $author = $this->person($who);

            $story = Story::create([
                'user_id' => $author->id,
                'title' => $title,
                'body' => $body,
                'category' => $category,
                'media_url' => $photo,
            ]);

            // The canvas shows three reaction chips whose counts are all derived
            // from one number; we store one reaction per person, so that number
            // is the one that survives - as claps, on the chip it belongs to.
            $this->roomOf(collect([$author]), $reactions + 1, $index)->skip(1)
                ->each(fn (User $u) => $story->reactions()->firstOrCreate(
                    ['user_id' => $u->id],
                    ['reaction' => 'clap'],
                ));

            $story->syncReactionsCount();
        }
    }

    /**
     * The people the canvas names, topped up to the headcount it prints.
     *
     * Walking the roster from a per-row offset rather than taking a random slice
     * keeps two things true at once: the counts on the cards are the canvas's
     * numbers, and reseeding puts the same people back in the same rooms.
     */
    protected function roomOf(Collection $named, int $total, int $offset): Collection
    {
        $room = $named->unique('id')->values();

        if ($room->count() >= $total) {
            return $room->take($total);
        }

        $rotated = $this->roster->slice($offset * 7 % max($this->roster->count(), 1))
            ->concat($this->roster)->values();

        foreach ($rotated as $candidate) {
            if ($room->count() >= $total) {
                break;
            }

            if (! $room->contains('id', $candidate->id)) {
                $room->push($candidate);
            }
        }

        return $room;
    }

    /** A canvas name, resolved to the colleague standing in for them. */
    protected function person(string $canvasName): User
    {
        $name = self::CANVAS_PEOPLE[$canvasName] ?? $canvasName;

        return $this->roster->firstWhere('name', $name)
            ?? throw new RuntimeException("\"{$canvasName}\" maps to \"{$name}\", who is not on the roster.");
    }

    /** One set of avatar initials off an event card. */
    protected function personByInitials(string $initials): User
    {
        $name = self::CANVAS_INITIALS[$initials]
            ?? throw new RuntimeException("Avatar initials \"{$initials}\" are not mapped to anyone.");

        return $this->roster->firstWhere('name', $name)
            ?? throw new RuntimeException("Avatar initials \"{$initials}\" map to \"{$name}\", who is not on the roster.");
    }
}
