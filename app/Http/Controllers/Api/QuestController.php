<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\QuestResource;
use App\Models\QuestTarget;
use App\Services\Notifier;
use App\Services\QuestBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class QuestController extends Controller
{
    public function __construct(protected Notifier $notifier) {}

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

        $wasActive = $quest->status === 'active';
        $quest->refreshCompletion();

        // No actor — this is the app congratulating you on your own quest.
        if ($wasActive && $quest->fresh()->status === 'completed') {
            $this->notifier->send($quest->user_id, 'quest.completed', [
                'title' => 'New Joiner Quest complete',
                'body' => "You've been through everyone on your list. Nicely done.",
                'subject' => $quest,
                'action_url' => '/me',
            ]);
        }

        return response()->json([
            'data' => new QuestResource($quest->fresh()->load('targets.targetUser')),
        ]);
    }
}
