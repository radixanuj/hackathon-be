<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SessionRequestResource;
use App\Models\SessionRequest;
use App\Models\User;
use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Mentoring / Knowledge Sessions: 30 minutes with another employee, asked for
 * on the strength of something on their profile. The recipient can accept,
 * decline, or suggest another time.
 */
class SessionRequestController extends Controller
{
    public function __construct(protected Notifier $notifier) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'direction' => ['nullable', Rule::in(['incoming', 'outgoing', 'all'])],
            'status' => ['nullable', 'string'],
            'kind' => ['nullable', Rule::in(SessionRequest::KINDS)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $userId = $request->user()->id;
        $direction = $filters['direction'] ?? 'all';

        $query = SessionRequest::query()
            ->with(['requester', 'recipient', 'tag'])
            ->when($direction === 'incoming', fn ($q) => $q->where('recipient_id', $userId))
            ->when($direction === 'outgoing', fn ($q) => $q->where('requester_id', $userId))
            ->when($direction === 'all', fn ($q) => $q->where(
                fn ($sub) => $sub->where('recipient_id', $userId)->orWhere('requester_id', $userId)
            ))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->orderByDesc('id');

        return SessionRequestResource::collection(
            $query->paginate($filters['per_page'] ?? 20)->withQueryString()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'recipient_id' => ['required', 'integer', 'exists:users,id'],
            'kind' => ['nullable', Rule::in(SessionRequest::KINDS)],
            'topic' => ['required', 'string', 'max:160'],
            'category' => ['required', Rule::in(SessionRequest::CATEGORIES)],
            'tag_id' => ['nullable', 'integer', 'exists:tags,id'],
            'message' => ['nullable', 'string', 'max:2000'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:120'],
            'proposed_at' => ['nullable', 'date', 'after:now'],
        ]);

        $requester = $request->user();

        if ((int) $data['recipient_id'] === $requester->id) {
            return response()->json(['message' => 'You cannot request a session with yourself.'], 422);
        }

        $recipient = User::findOrFail($data['recipient_id']);

        if (! $recipient->open_to_mentoring) {
            return response()->json(['message' => 'This person is not taking session requests right now.'], 422);
        }

        $kind = $data['kind'] ?? 'knowledge';

        // Anyone open to being asked can take a knowledge session or coaching;
        // mentoring is a standing commitment, and only the roster carries it.
        if ($kind === 'mentoring' && ! $recipient->is_mentor) {
            return response()->json(['message' => 'This person is not on the mentoring roster.'], 422);
        }

        $sessionRequest = SessionRequest::create($data + [
            'requester_id' => $requester->id,
            'kind' => $kind,
            'duration_minutes' => $data['duration_minutes'] ?? 30,
        ]);

        $this->notifier->send($recipient, 'session_request.received', [
            'actor' => $requester,
            'title' => match ($sessionRequest->kind) {
                'mentoring' => $requester->name.' asked you to mentor them',
                'coaching' => $requester->name.' asked you to coach them',
                default => $requester->name.' asked you for '.$sessionRequest->duration_minutes.' minutes',
            },
            'body' => $sessionRequest->topic,
            'subject' => $sessionRequest,
            'action_url' => '/connect',
        ]);

        // `status` comes off a column default, so the row has to be re-read for
        // the response to carry it rather than a null.
        return response()->json([
            'data' => new SessionRequestResource($sessionRequest->fresh()->load(['requester', 'recipient', 'tag'])),
        ], 201);
    }

    public function show(Request $request, SessionRequest $sessionRequest): JsonResponse
    {
        $this->assertParticipant($request, $sessionRequest);

        return response()->json([
            'data' => new SessionRequestResource($sessionRequest->load(['requester', 'recipient', 'tag'])),
        ]);
    }

    /** The recipient's reply: accept, decline, or suggest another time. */
    public function respond(Request $request, SessionRequest $sessionRequest): JsonResponse
    {
        if ($sessionRequest->recipient_id !== $request->user()->id) {
            throw new AccessDeniedHttpException('Only the recipient can respond to this request.');
        }

        $data = $request->validate([
            'action' => ['required', Rule::in(['accept', 'decline', 'suggest_time'])],
            'scheduled_at' => ['required_if:action,accept,suggest_time', 'nullable', 'date'],
            'response_message' => ['nullable', 'string', 'max:2000'],
        ]);

        $status = match ($data['action']) {
            'accept' => 'accepted',
            'decline' => 'declined',
            'suggest_time' => 'time_suggested',
        };

        $sessionRequest->update([
            'status' => $status,
            'scheduled_at' => $data['scheduled_at'] ?? $sessionRequest->scheduled_at,
            'response_message' => $data['response_message'] ?? null,
            'responded_at' => now(),
        ]);

        $responder = $request->user();
        $when = $sessionRequest->fresh()->scheduled_at?->format('D j M, g:ia');

        $this->notifier->send($sessionRequest->requester_id, 'session_request.'.$status, [
            'actor' => $responder,
            'title' => match ($data['action']) {
                'accept' => $responder->name.' said yes',
                'decline' => $responder->name.' passed on your request',
                'suggest_time' => $responder->name.' suggested another time',
            },
            'body' => match ($data['action']) {
                'accept' => $sessionRequest->topic.($when ? ' — '.$when : ''),
                'decline' => $data['response_message'] ?? $sessionRequest->topic,
                'suggest_time' => $when ? 'How about '.$when.'?' : $sessionRequest->topic,
            },
            'subject' => $sessionRequest,
            'action_url' => '/connect',
        ]);

        return response()->json([
            'data' => new SessionRequestResource($sessionRequest->fresh()->load(['requester', 'recipient', 'tag'])),
        ]);
    }

    /** Either side can mark it done; only the requester can cancel it. */
    public function update(Request $request, SessionRequest $sessionRequest): JsonResponse
    {
        $this->assertParticipant($request, $sessionRequest);

        $data = $request->validate([
            'status' => ['required', Rule::in(['completed', 'cancelled'])],
        ]);

        if ($data['status'] === 'cancelled' && $sessionRequest->requester_id !== $request->user()->id) {
            throw new AccessDeniedHttpException('Only the requester can cancel this request.');
        }

        $sessionRequest->update(['status' => $data['status']]);

        // Whichever side did it, the other one is the one who needs telling.
        $actor = $request->user();
        $other = $sessionRequest->requester_id === $actor->id
            ? $sessionRequest->recipient_id
            : $sessionRequest->requester_id;

        $this->notifier->send($other, 'session_request.'.$data['status'], [
            'actor' => $actor,
            'title' => $data['status'] === 'cancelled'
                ? $actor->name.' cancelled your session'
                : $actor->name.' marked your session as done',
            'body' => $sessionRequest->topic,
            'subject' => $sessionRequest,
            'action_url' => '/connect',
        ]);

        return response()->json([
            'data' => new SessionRequestResource($sessionRequest->fresh()->load(['requester', 'recipient', 'tag'])),
        ]);
    }

    protected function assertParticipant(Request $request, SessionRequest $sessionRequest): void
    {
        $userId = $request->user()->id;

        if ($sessionRequest->requester_id !== $userId && $sessionRequest->recipient_id !== $userId) {
            throw new AccessDeniedHttpException('This session request is not yours.');
        }
    }
}
