<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ama;
use App\Models\Event;
use App\Models\EventRsvp;
use App\Models\InterestGroup;
use App\Models\Recommendation;
use App\Models\SessionRequest;
use App\Models\Story;
use App\Models\StoryReaction;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/** Every enum and filter option the frontend needs, in one call. */
class MetaController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'teams' => User::query()->whereNotNull('team')->distinct()->orderBy('team')->pluck('team'),
                'locations' => User::query()->whereNotNull('location')->distinct()->orderBy('location')->pluck('location'),
                'tag_kinds' => User::TAG_KINDS,
                'tag_types' => Tag::TYPES,
                'session_categories' => SessionRequest::CATEGORIES,
                'group_categories' => InterestGroup::CATEGORIES,
                'group_platforms' => ['whatsapp', 'slack', 'teams', 'discord', 'other'],
                'recommendation_types' => Recommendation::TYPES,
                'recommendation_streams' => Recommendation::STREAMS,
                'ama_formats' => Ama::FORMATS,
                'event_categories' => Event::CATEGORIES,
                'rsvp_statuses' => EventRsvp::STATUSES,
                'story_categories' => Story::CATEGORIES,
                'story_reactions' => StoryReaction::REACTIONS,
            ],
        ]);
    }
}
