<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\QuestResource;
use App\Models\QuestTarget;
use App\Services\QuestBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class QuestController extends Controller
{
    /** The signed-in user's New Joiner Quest, built on first request if missing. */
    public function show(Request $request, QuestBuilder $builder): JsonResponse
    {
        $user = $request->user();
        $quest = $user->quest()->with('targets.targetUser')->first();

        if (! $quest) {
            $quest = $builder->buildFor($user);
        }

        return response()->json(['data' => new QuestResource($quest)]);
    }

    /** Rebuild the five suggestions from scratch. */
    public function regenerate(Request $request, QuestBuilder $builder): JsonResponse
    {
        return response()->json([
            'data' => new QuestResource($builder->buildFor($request->user())),
        ]);
    }

    public function updateTarget(Request $request, QuestTarget $target): JsonResponse
    {
        $quest = $target->quest;

        if ($quest->user_id !== $request->user()->id) {
            throw new AccessDeniedHttpException('This quest belongs to someone else.');
        }

        $data = $request->validate([
            'status' => ['required', Rule::in(['pending', 'met', 'skipped'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $target->update([
            'status' => $data['status'],
            'note' => $data['note'] ?? $target->note,
            'met_at' => $data['status'] === 'met' ? now() : null,
        ]);

        $quest->refreshCompletion();

        return response()->json([
            'data' => new QuestResource($quest->fresh()->load('targets.targetUser')),
        ]);
    }
}
