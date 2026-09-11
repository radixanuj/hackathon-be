<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const TAG_KINDS = ['can_talk_about', 'can_help_with', 'want_to_learn', 'interest'];

    /** "Currently into" stays a glance, not a list. Four lines is already plenty. */
    public const CURRENTLY_MAX = 4;

    /**
     * The emoji a "Currently into" line gets when the client doesn't send one.
     *
     * Resolved here rather than in the UI so a line written through the API,
     * the seeder or the profile card all draw the same icon. Labels are open
     * text, so anything unlisted falls back to the sparkle.
     */
    protected const CURRENTLY_ICONS = [
        'reading' => '📖',
        'listening to' => '🎧',
        'watching' => '📺',
        'training for' => '🏃',
        'building' => '🛠',
        'playing' => '🎮',
        'cooking' => '🥘',
        'baking' => '🥖',
        'perfecting' => '🥖',
        'shooting' => '📷',
        'following' => '🏏',
        'planning' => '🗺',
        'learning' => '🎸',
        'relearning' => '🛹',
        'making' => '🏺',
        'writing' => '✍️',
        'riding' => '🚴',
    ];

    protected $fillable = [
        'name', 'email', 'password', 'job_title', 'team', 'location', 'timezone',
        'joined_at', 'intro', 'currently', 'avatar_url', 'pronouns', 'role', 'is_active',
        'open_to_mentoring', 'open_to_blind_meetups', 'is_mentor',
    ];

    protected $hidden = ['password', 'remember_token'];

    /** Mirrors the column defaults, so a freshly created model reports them too. */
    protected $attributes = [
        'role' => 'employee',
        'is_active' => true,
        'open_to_mentoring' => true,
        'open_to_blind_meetups' => true,
        'is_mentor' => false,
        'timezone' => 'Asia/Kolkata',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'joined_at' => 'date',
            'currently' => 'array',
            'is_active' => 'boolean',
            'open_to_mentoring' => 'boolean',
            'open_to_blind_meetups' => 'boolean',
            'is_mentor' => 'boolean',
        ];
    }

    /** Whole years since joining Radix. */
    public function tenureYears(): float
    {
        if (! $this->joined_at) {
            return 0;
        }

        return round($this->joined_at->diffInDays(now()) / 365.25, 1);
    }

    /** The Blind Meetup cohort split: 6+ years vs under 6 years. */
    public function tenureBand(): string
    {
        return $this->tenureYears() >= 6 ? 'senior' : 'junior';
    }

    public function isNewJoiner(): bool
    {
        return $this->joined_at !== null && $this->joined_at->greaterThan(now()->subDays(45));
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /** Just the first name, for copy that addresses somebody directly. */
    public function firstName(): string
    {
        return explode(' ', trim($this->name))[0] ?: $this->name;
    }

    /**
     * The "Currently into" lines, cleaned up for display.
     *
     * Blank rows are dropped rather than stored as empty labels: the card reads
     * as "Reading: Project Hail Mary", and a half-filled row reads as a bug.
     *
     * @return array<int, array{icon: string, label: string, value: string}>
     */
    public function currentlyEntries(): array
    {
        return collect($this->currently ?? [])
            ->map(fn ($entry) => [
                'icon' => trim((string) ($entry['icon'] ?? '')) ?: self::iconFor((string) ($entry['label'] ?? '')),
                'label' => trim((string) ($entry['label'] ?? '')),
                'value' => trim((string) ($entry['value'] ?? '')),
            ])
            ->filter(fn (array $entry) => $entry['label'] !== '' && $entry['value'] !== '')
            ->take(self::CURRENTLY_MAX)
            ->values()
            ->all();
    }

    public static function iconFor(string $label): string
    {
        return self::CURRENTLY_ICONS[mb_strtolower(trim($label))] ?? '✨';
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'user_tag')->withPivot('kind')->withTimestamps();
    }

    public function tagsOfKind(string $kind): BelongsToMany
    {
        return $this->tags()->wherePivot('kind', $kind);
    }

    public function quest(): HasOne
    {
        return $this->hasOne(Quest::class);
    }

    public function interestGroups(): BelongsToMany
    {
        return $this->belongsToMany(InterestGroup::class, 'interest_group_members')
            ->withPivot('role')->withTimestamps();
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(Recommendation::class);
    }

    public function stories(): HasMany
    {
        return $this->hasMany(Story::class);
    }

    public function hostedAmas(): HasMany
    {
        return $this->hasMany(Ama::class, 'host_id');
    }

    public function hostedEvents(): HasMany
    {
        return $this->hasMany(Event::class, 'host_id');
    }

    public function sentSessionRequests(): HasMany
    {
        return $this->hasMany(SessionRequest::class, 'requester_id');
    }

    public function receivedSessionRequests(): HasMany
    {
        return $this->hasMany(SessionRequest::class, 'recipient_id');
    }

    // --- Phase 2 -------------------------------------------------------------

    public function buddySignup(): HasOne
    {
        return $this->hasOne(BuddySignup::class);
    }

    public function officeHourSlots(): HasMany
    {
        return $this->hasMany(OfficeHourSlot::class, 'host_id');
    }

    public function coffeeInvites(): HasMany
    {
        return $this->hasMany(CoffeeInvite::class, 'host_id');
    }

    public function challengeParticipations(): HasMany
    {
        return $this->hasMany(ChallengeParticipant::class);
    }

    public function radixQuestions(): HasMany
    {
        return $this->hasMany(RadixQuestion::class);
    }

    public function teachOffers(): HasMany
    {
        return $this->hasMany(TeachOffer::class);
    }

    public function openInvites(): HasMany
    {
        return $this->hasMany(OpenInvite::class);
    }

    public function suggestionDismissals(): HasMany
    {
        return $this->hasMany(SuggestionDismissal::class);
    }

    /**
     * This person's notification inbox.
     *
     * Deliberately shadows the relation Notifiable provides: the app raises its
     * own App\Models\Notification rows through App\Services\Notifier rather
     * than going through Laravel's notification channels, and `notifications`
     * should mean that list everywhere.
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class)->latest('id');
    }

    public function unreadNotificationsCount(): int
    {
        return $this->notifications()->inbox()->unread()->count();
    }

    /** Nudges this person has sent — the poke, one row per tap. */
    public function sentNudges(): HasMany
    {
        return $this->hasMany(Nudge::class, 'sender_id');
    }

    public function receivedNudges(): HasMany
    {
        return $this->hasMany(Nudge::class, 'recipient_id');
    }

    /** The buddy pairing currently in force, if any. */
    public function activeBuddyPairing(): ?BuddyPairing
    {
        return BuddyPairing::forUser($this->id)->where('status', 'active')->first();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Free-text search across name, title, intro and tags. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        $like = '%'.$term.'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('name', 'like', $like)
                ->orWhere('job_title', 'like', $like)
                ->orWhere('intro', 'like', $like)
                ->orWhere('team', 'like', $like)
                ->orWhere('location', 'like', $like)
                ->orWhereHas('tags', fn (Builder $t) => $t->where('name', 'like', $like));
        });
    }
}
