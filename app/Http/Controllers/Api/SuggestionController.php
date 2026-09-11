<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SuggestionResource;
use App\Models\SuggestionDismissal;
use App\Models\User;
use App\Services\ConnectionSuggester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Who Should I Meet? — People, Phase 2. */
class SuggestionController extends Controller
{
    public function index(Request $request, ConnectionSuggester $suggester): JsonResponse
    {
        $filters = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $suggestions = $suggester->suggestFor(
            $request->user(),
            $filters['limit'] ?? ConnectionSuggester::DEFAULT_COUNT,
        );

        return response()->json(['data' => SuggestionResource::collection($suggestions)]);
    }

    /** "Not right now" — keeps this person out of future suggestions. */
    public function dismiss(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot dismiss yourself.'], 422);
        }

        SuggestionDismissal::firstOrCreate([
            'user_id' => $request->user()->id,
            'dismissed_user_id' => $user->id,
        ]);

        return response()->json(['message' => 'Removed from your suggestions.']);
    }

    public function undismiss(Request $request, User $user): JsonResponse
    {
        SuggestionDismissal::where('user_id', $request->user()->id)
            ->where('dismissed_user_id', $user->id)
            ->delete();

        return response()->json(['message' => 'Back in your suggestions.']);
    }
}
