<?php

use App\Http\Controllers\Api\AmaController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\InterestGroupController;
use App\Http\Controllers\Api\MeetupController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\Api\QuestController;
use App\Http\Controllers\Api\RecommendationController;
use App\Http\Controllers\Api\SessionRequestController;
use App\Http\Controllers\Api\StoryController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Radix Connect API - Phase 1
|--------------------------------------------------------------------------
| Grouped by product pillar:
|   People                -> profiles, tags, New Joiner Quest
|   Connect               -> blind meetups, mentoring sessions
|   Communities           -> interest groups
|   Learn & Share         -> recommendations, AMAs
|   Do Together           -> events
|   Celebrate & Discover  -> stories
*/

Route::prefix('v1')->group(function () {
    // Demo sign-in: name + the shared password. Real email/password login is
    // left intact alongside it - see config/radix.php.
    Route::post('auth/demo-login', [AuthController::class, 'demoLogin']);

    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::get('meta', MetaController::class);
        Route::get('dashboard', DashboardController::class);

        // --- People ----------------------------------------------------------
        Route::get('users', [UserController::class, 'index']);
        Route::get('users/{user}', [UserController::class, 'show']);
        Route::patch('me', [UserController::class, 'updateMe']);
        Route::put('me/tags', [UserController::class, 'syncTags']);

        Route::get('tags', [TagController::class, 'index']);
        Route::post('tags', [TagController::class, 'store']);

        Route::get('me/quest', [QuestController::class, 'show']);
        Route::post('me/quest/regenerate', [QuestController::class, 'regenerate']);
        Route::patch('quest-targets/{target}', [QuestController::class, 'updateTarget']);

        // --- Connect: Blind Meetups ------------------------------------------
        Route::get('meetups/rounds', [MeetupController::class, 'index']);
        Route::get('meetups/rounds/current', [MeetupController::class, 'current']);
        Route::post('meetups/rounds', [MeetupController::class, 'store']);                     // admin
        Route::get('meetups/rounds/{round}', [MeetupController::class, 'show']);
        Route::post('meetups/rounds/{round}/signup', [MeetupController::class, 'signUp']);
        Route::delete('meetups/rounds/{round}/signup', [MeetupController::class, 'withdraw']);
        Route::post('meetups/rounds/{round}/match', [MeetupController::class, 'runMatching']); // admin
        Route::get('me/meetup-pairs', [MeetupController::class, 'myPairs']);
        Route::patch('meetups/pairs/{pair}', [MeetupController::class, 'updatePair']);

        // --- Connect: Mentoring / Knowledge Sessions -------------------------
        Route::get('session-requests', [SessionRequestController::class, 'index']);
        Route::post('session-requests', [SessionRequestController::class, 'store']);
        Route::get('session-requests/{sessionRequest}', [SessionRequestController::class, 'show']);
        Route::post('session-requests/{sessionRequest}/respond', [SessionRequestController::class, 'respond']);
        Route::patch('session-requests/{sessionRequest}', [SessionRequestController::class, 'update']);

        // --- Communities: Interest Groups ------------------------------------
        Route::get('groups', [InterestGroupController::class, 'index']);
        Route::post('groups', [InterestGroupController::class, 'store']);
        Route::get('groups/{group}', [InterestGroupController::class, 'show']);
        Route::patch('groups/{group}', [InterestGroupController::class, 'update']);
        Route::delete('groups/{group}', [InterestGroupController::class, 'destroy']);
        Route::post('groups/{group}/join', [InterestGroupController::class, 'join']);
        Route::delete('groups/{group}/join', [InterestGroupController::class, 'leave']);
        Route::get('groups/{group}/members', [InterestGroupController::class, 'members']);

        // --- Learn & Share: Recommendations ----------------------------------
        Route::get('recommendations', [RecommendationController::class, 'index']);
        Route::post('recommendations', [RecommendationController::class, 'store']);
        Route::get('recommendations/{recommendation}', [RecommendationController::class, 'show']);
        Route::patch('recommendations/{recommendation}', [RecommendationController::class, 'update']);
        Route::delete('recommendations/{recommendation}', [RecommendationController::class, 'destroy']);
        Route::post('recommendations/{recommendation}/like', [RecommendationController::class, 'like']);
        Route::delete('recommendations/{recommendation}/like', [RecommendationController::class, 'unlike']);

        // --- Learn & Share: AMAs ---------------------------------------------
        Route::get('amas', [AmaController::class, 'index']);
        Route::post('amas', [AmaController::class, 'store']);
        Route::get('amas/{ama}', [AmaController::class, 'show']);
        Route::patch('amas/{ama}', [AmaController::class, 'update']);
        Route::delete('amas/{ama}', [AmaController::class, 'destroy']);
        Route::post('amas/{ama}/questions', [AmaController::class, 'askQuestion']);
        Route::delete('ama-questions/{question}', [AmaController::class, 'deleteQuestion']);
        Route::post('ama-questions/{question}/upvote', [AmaController::class, 'upvoteQuestion']);
        Route::delete('ama-questions/{question}/upvote', [AmaController::class, 'removeUpvote']);
        Route::post('ama-questions/{question}/answers', [AmaController::class, 'answerQuestion']);

        // --- Do Together: Events ---------------------------------------------
        Route::get('events', [EventController::class, 'index']);
        Route::post('events', [EventController::class, 'store']);
        Route::get('events/{event}', [EventController::class, 'show']);
        Route::patch('events/{event}', [EventController::class, 'update']);
        Route::delete('events/{event}', [EventController::class, 'destroy']);
        Route::post('events/{event}/rsvp', [EventController::class, 'rsvp']);
        Route::get('events/{event}/attendees', [EventController::class, 'attendees']);

        // --- Celebrate & Discover: Stories ------------------------------------
        Route::get('stories', [StoryController::class, 'index']);
        Route::post('stories', [StoryController::class, 'store']);
        Route::get('stories/{story}', [StoryController::class, 'show']);
        Route::patch('stories/{story}', [StoryController::class, 'update']);
        Route::delete('stories/{story}', [StoryController::class, 'destroy']);
        Route::post('stories/{story}/react', [StoryController::class, 'react']);
        Route::delete('stories/{story}/react', [StoryController::class, 'removeReaction']);
        Route::post('stories/{story}/convert-to-ama', [StoryController::class, 'convertToAma']);
    });
});
