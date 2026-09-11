<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NudgeResource;
use App\Models\Nudge;
use App\Models\User;
use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Nudges — the old-fashioned poke.
 *
 * No message, no meeting, no agenda. The point is the lowest-effort way to tell
 * a colleague you thought of them, and the reply is the same gesture back.
 *
 * One rule holds the whole thing up: you cannot nudge the same person twice in a
 * row. Until they nudge back it stays their turn, which makes a nudge worth
 * something and makes nudging all 98 people in a loop impossible by
 * construction rather than by rate limit.
 */
class NudgeController extends Controller
{
    public function __construct(protected Notifier $notifier) {}

    /**
     * Your exchanges, newest first.
     *
     * Defaults to everything involving you, in both directions; `scope` narrows
     * to one side and `status=outstanding` gives just the ones with a turn
     * pending — which, filtered to `received`, is "who is waiting on me".
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'scope' => ['nullable', Rule::in(['all', 'sent', 'received'])],
            'status' => ['nullable', Rule::in(['all', 'outstanding', 'returned'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $me = $request->user()->id;
        $scope = $filters['scope'] ?? 'all';
        $status = $filters['status'] ?? 'all';

        $nudges = Nudge::query()
            ->with(['sender', 'recipient'])
            ->when($scope === 'all', fn ($q) => $q->involving($me))
            ->when($scope === 'sent', fn ($q) => $q->where('sender_id', $me))
            ->when($scope === 'received', fn ($q) => $q->where('recipient_id', $me))
            ->when($status === 'outstanding', fn ($q) => $q->outstanding())
            ->when($status === 'returned', fn ($q) => $q->whereNotNull('returned_at'))
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return NudgeResource::collection($nudges)
            ->additional(['summary' => $this->counts($me)]);
    }

    /** What the "N waiting on you" badge needs, on its own. */
    public function summary(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->counts($request->user()->id)]);
    }

    /**
     * Nudge someone — or nudge them back, which is the same gesture and the
     * same endpoint. Whether it counts as a reply depends only on who nudged
     * last, so the caller never has to say which it is doing.
     */
    public function store(Request $request, User $user): JsonResponse
    {
        $sender = $request->user();

        if ($sender->id === $user->id) {
            return response()->json(['message' => 'Nudging yourself is not the point.'], 422);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'This person is no longer here.'], 422);
        }

        // Two taps landing together must not both get through: the pair is
        // locked for the read so the second one sees the first one's row.
        $result = DB::transaction(function () use ($sender, $user) {
            $latest = Nudge::query()
                ->between($sender->id, $user->id)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($latest?->isOutstanding() && $latest->sender_id === $sender->id) {
                return null;
            }

            // Their nudge is being answered, so it is no longer outstanding.
            $latest?->update(['returned_at' => now()]);

            return Nudge::create([
                'sender_id' => $sender->id,
                'recipient_id' => $user->id,
                // One deeper than wherever the exchange had got to.
                'streak' => ($latest?->streak ?? 0) + 1,
            ]);
        });

        if (! $result) {
            return response()->json([
                'message' => "You have already nudged {$user->firstName()}. It is their turn now.",
            ], 422);
        }

        $this->notifier->send($user, 'nudge.received', [
            'actor' => $sender,
            'title' => $sender->name.' nudged you',
            'body' => $result->streak > 1
                ? "That is {$result->streak} nudges between you two. Nudge back?"
                : 'No reason, no agenda. Nudge back?',
            'subject' => $result,
            // Straight to their profile, where the Nudge back button is.
            'action_url' => '/people?profile='.$sender->id,
            'data' => ['streak' => $result->streak],
        ]);

        return response()->json([
            'message' => $result->streak > 1
                ? "Nudged back. {$result->streak} nudges deep."
                : "You nudged {$user->firstName()}.",
            'data' => new NudgeResource($result->load(['sender', 'recipient'])),
        ], 201);
    }

    /** Where you and one other person stand, for a profile's Nudge button. */
    public function show(Request $request, User $user): JsonResponse
    {
        return response()->json([
            'data' => Nudge::stateFor($request->user()->id, $user->id),
        ]);
    }

    protected function counts(int $userId): array
    {
        return [
            'waiting_on_you' => Nudge::query()->where('recipient_id', $userId)->outstanding()->count(),
            'waiting_on_them' => Nudge::query()->where('sender_id', $userId)->outstanding()->count(),
            'total' => Nudge::query()->involving($userId)->count(),
        ];
    }
}
