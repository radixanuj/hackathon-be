<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Nudge;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /** Profiles are searchable by skills, interests, team and location. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'team' => ['nullable', 'string'],
            'location' => ['nullable', 'string'],
            'skill' => ['nullable', 'string'],
            'interest' => ['nullable', 'string'],
            'tag' => ['nullable', 'string'],
            // One kind, or several comma-separated: "who can help with or talk
            // about React" is one question, not two.
            'kind' => ['nullable', 'string'],
            'tenure_band' => ['nullable', Rule::in(['senior', 'junior'])],
            'new_joiners' => ['nullable', 'boolean'],
            'open_to_mentoring' => ['nullable', 'boolean'],
            // The mentoring roster proper, not the wider "open to being asked" flag.
            'mentors' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = User::query()
            ->active()
            ->with('tags')
            ->search($filters['q'] ?? null);

        if ($team = $filters['team'] ?? null) {
            $query->where('team', $team);
        }

        if ($location = $filters['location'] ?? null) {
            $query->where('location', $location);
        }

        // `skill` and `interest` narrow by tag slug within the matching tag type;
        // `tag` matches either, optionally constrained to one profile section.
        foreach (['skill' => 'skill', 'interest' => 'interest'] as $param => $type) {
            if ($slug = $filters[$param] ?? null) {
                $query->whereHas('tags', fn ($t) => $t->where('slug', $slug)->where('type', $type));
            }
        }

        if ($slug = $filters['tag'] ?? null) {
            $kinds = collect(explode(',', (string) ($filters['kind'] ?? '')))
                ->map(fn (string $kind) => trim($kind))
                ->filter(fn (string $kind) => in_array($kind, User::TAG_KINDS, true))
                ->values();

            $query->whereHas('tags', function ($t) use ($slug, $kinds) {
                $t->where('slug', $slug);
                if ($kinds->isNotEmpty()) {
                    $t->whereIn('user_tag.kind', $kinds->all());
                }
            });
        }

        if ($band = $filters['tenure_band'] ?? null) {
            $cutoff = now()->subYears(6)->toDateString();
            $band === 'senior'
                ? $query->whereNotNull('joined_at')->where('joined_at', '<=', $cutoff)
                : $query->where(fn ($q) => $q->whereNull('joined_at')->orWhere('joined_at', '>', $cutoff));
        }

        if ($request->boolean('new_joiners')) {
            $query->where('joined_at', '>=', now()->subDays(45)->toDateString());
        }

        if ($request->has('open_to_mentoring')) {
            $query->where('open_to_mentoring', $request->boolean('open_to_mentoring'));
        }

        if ($request->has('mentors')) {
            $query->where('is_mentor', $request->boolean('mentors'));
        }

        return UserResource::collection(
            $query->orderBy('name')->paginate($filters['per_page'] ?? 20)->withQueryString()
        );
    }

    public function show(Request $request, User $user): UserResource
    {
        $user->load('tags');
        // Where the two of you stand on nudges, so a profile can draw its Nudge
        // button — and know whether it says "back" — without a second call.
        $user->nudge_state = Nudge::stateFor($request->user()->id, $user->id);

        return new UserResource($user);
    }

    public function updateMe(Request $request): UserResource
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'team' => ['sometimes', 'nullable', 'string', 'max:80'],
            'location' => ['sometimes', 'nullable', 'string', 'max:80'],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'joined_at' => ['sometimes', 'nullable', 'date'],
            'intro' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'avatar_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'pronouns' => ['sometimes', 'nullable', 'string', 'max:40'],
            'open_to_mentoring' => ['sometimes', 'boolean'],
            'open_to_blind_meetups' => ['sometimes', 'boolean'],
        ]);

        $user->update($data);

        return new UserResource($user->fresh()->load('tags'));
    }

    /**
     * Replace the whole "Currently into" list.
     *
     * Sent whole, like a tag section: the card is edited as one block of three
     * or four lines, and a partial write would leave no way to delete a line.
     */
    public function updateCurrently(Request $request): UserResource
    {
        $user = $request->user();

        $data = $request->validate([
            'currently' => ['present', 'array', 'max:'.User::CURRENTLY_MAX],
            'currently.*.label' => ['required', 'string', 'max:40'],
            'currently.*.value' => ['nullable', 'string', 'max:140'],
            // Optional: left out, the label picks the icon.
            'currently.*.icon' => ['nullable', 'string', 'max:8'],
        ]);

        $user->update(['currently' => $data['currently']]);

        // Store it as it will be read back — blank lines dropped, icons resolved.
        $user->update(['currently' => $user->currentlyEntries()]);

        return new UserResource($user->fresh()->load('tags'));
    }

    /**
     * Replace one section of the profile's tags.
     *
     * The four sections - can talk about, can help with, want to learn and
     * interests - are edited independently, so a write only ever touches the
     * kind being sent.
     */
    public function syncTags(Request $request): UserResource
    {
        $user = $request->user();

        $data = $request->validate([
            'kind' => ['required', Rule::in(User::TAG_KINDS)],
            'tags' => ['present', 'array'],
            'tags.*' => ['string', 'max:60'],
        ]);

        $type = $data['kind'] === 'interest' ? 'interest' : 'skill';

        $tagIds = collect($data['tags'])
            ->map(fn (string $name) => trim($name))
            ->filter()
            ->unique()
            ->map(fn (string $name) => Tag::findOrCreateByName($name, $type)->id)
            ->all();

        $user->tags()->wherePivot('kind', $data['kind'])->detach();

        foreach ($tagIds as $tagId) {
            $user->tags()->attach($tagId, ['kind' => $data['kind']]);
        }

        Tag::whereIn('id', $tagIds)->each(fn (Tag $tag) => $tag->update([
            'usage_count' => $tag->users()->count(),
        ]));

        return new UserResource($user->fresh()->load('tags'));
    }
}
