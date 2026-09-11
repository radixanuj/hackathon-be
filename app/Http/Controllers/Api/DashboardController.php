<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AmaResource;
use App\Http\Resources\BuddyPairingResource;
use App\Http\Resources\ChallengeResource;
use App\Http\Resources\CoffeeInviteResource;
use App\Http\Resources\EventResource;
use App\Http\Resources\MeetupRoundResource;
use App\Http\Resources\OpenInviteResource;
use App\Http\Resources\QuestResource;
use App\Http\Resources\RadixQuestionResource;
use App\Http\Resources\RecommendationResource;
use App\Http\Resources\SessionRequestResource;
use App\Http\Resources\StoryResource;
use App\Http\Resources\SuggestionResource;
use App\Http\Resources\TeachOfferResource;
use App\Models\Ama;
use App\Models\Challenge;
use App\Models\CoffeeInvite;
use App\Models\Event;
use App\Models\MeetupRound;
use App\Models\OpenInvite;
use App\Models\RadixQuestion;
use App\Models\Recommendation;
use App\Models\SessionRequest;
use App\Models\Story;
use App\Models\TeachOffer;
use App\Services\ConnectionSuggester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** One call that fills the Radix Connect home screen across all six pillars. */
class DashboardController extends Controller
{
    public function __invoke(Request $request, ConnectionSuggester $suggester): JsonResponse
    {
        $user = $request->user();

        $round = MeetupRound::query()
            ->whereIn('status', ['open', 'matched'])
            ->where('meetup_date', '>=', now()->subDay()->toDateString())
            ->orderBy('meetup_date')
            ->withCount('signups')
            ->first();

        return response()->json([
            'data' => [
                'quest' => $user->quest()->with('targets.targetUser')->first()
                    ? new QuestResource($user->quest()->with('targets.targetUser')->first())
                    : null,
                'blind_meetup_round' => $round ? new MeetupRoundResource($round) : null,
                'pending_session_requests' => SessionRequestResource::collection(
                    SessionRequest::where('recipient_id', $user->id)
                        ->where('status', 'pending')
                        ->with(['requester', 'recipient'])
                        ->latest('id')->limit(5)->get()
                ),
                'upcoming_events' => EventResource::collection(
                    Event::upcoming()->with(['host', 'group'])->orderBy('starts_at')->limit(5)->get()
                ),
                'open_amas' => AmaResource::collection(
                    Ama::where('status', 'open')->with('host')->latest('id')->limit(5)->get()
                ),
                'latest_recommendations' => RecommendationResource::collection(
                    Recommendation::with('user')->latest('id')->limit(5)->get()
                ),
                'latest_stories' => StoryResource::collection(
                    Story::with(['user', 'tags'])->latest('id')->limit(5)->get()
                ),

                // --- Phase 2 ---
                'who_should_i_meet' => SuggestionResource::collection($suggester->suggestFor($user, 3)),
                'buddy' => ($buddy = $user->activeBuddyPairing())
                    ? new BuddyPairingResource($buddy->load(['userOne', 'userTwo']))
                    : null,
                'open_coffee_invites' => CoffeeInviteResource::collection(
                    CoffeeInvite::open()->where('host_id', '!=', $user->id)
                        ->with('host')->orderBy('starts_at')->limit(5)->get()
                ),
                'active_challenges' => ChallengeResource::collection(
                    Challenge::active()->with('creator')->orderBy('ends_on')->limit(5)->get()
                ),
                'questions_i_could_answer' => RadixQuestionResource::collection(
                    $this->questionsForMe($user)
                ),
                'teach_offers_seeking_interest' => TeachOfferResource::collection(
                    TeachOffer::where('status', 'open')->where('user_id', '!=', $user->id)
                        ->with(['user', 'tag'])->latest('id')->limit(5)->get()
                ),
                'open_invites' => OpenInviteResource::collection(
                    OpenInvite::where('status', 'open')->with('user')
                        ->orderByDesc('interested_count')->limit(5)->get()
                ),
            ],
        ]);
    }

    /** Open Ask Radix questions tagged with something this person can help with. */
    protected function questionsForMe($user)
    {
        $myTagIds = $user->tags()
            ->wherePivotIn('kind', ['can_talk_about', 'can_help_with'])
            ->pluck('tags.id');

        if ($myTagIds->isEmpty()) {
            return collect();
        }

        return RadixQuestion::where('status', 'open')
            ->where('user_id', '!=', $user->id)
            ->whereHas('tags', fn ($t) => $t->whereIn('tags.id', $myTagIds))
            ->with(['user', 'tags'])
            ->latest('id')
            ->limit(5)
            ->get();
    }
}
