<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AmaResource;
use App\Http\Resources\EventResource;
use App\Http\Resources\MeetupRoundResource;
use App\Http\Resources\QuestResource;
use App\Http\Resources\RecommendationResource;
use App\Http\Resources\SessionRequestResource;
use App\Http\Resources\StoryResource;
use App\Models\Ama;
use App\Models\Event;
use App\Models\MeetupRound;
use App\Models\Recommendation;
use App\Models\SessionRequest;
use App\Models\Story;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** One call that fills the Radix Connect home screen across all six pillars. */
class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
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
                    Story::with('user')->latest('id')->limit(5)->get()
                ),
            ],
        ]);
    }
}
