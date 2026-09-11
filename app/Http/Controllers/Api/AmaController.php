<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AmaAnswerResource;
use App\Http\Resources\AmaQuestionResource;
use App\Http\Resources\AmaResource;
use App\Models\Ama;
use App\Models\AmaQuestion;
use App\Models\AmaQuestionVote;
use App\Models\Story;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** AMAs: anyone with interesting experience can host one, async or live. */
class AmaController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['draft', 'open', 'scheduled', 'closed'])],
            'format' => ['nullable', Rule::in(Ama::FORMATS)],
            'host_id' => ['nullable', 'integer', 'exists:users,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $amas = Ama::query()
            ->with('host')
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(
                fn ($sub) => $sub->where('title', 'like', '%'.$term.'%')
                    ->orWhere('description', 'like', '%'.$term.'%')
            ))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status), fn ($q) => $q->where('status', '!=', 'draft'))
            ->when($filters['format'] ?? null, fn ($q, $format) => $q->where('format', $format))
            ->when($filters['host_id'] ?? null, fn ($q, $id) => $q->where('host_id', $id))
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return AmaResource::collection($amas);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:4000'],
            'format' => ['required', Rule::in(Ama::FORMATS)],
            'status' => ['nullable', Rule::in(['draft', 'open', 'scheduled'])],
            'opens_at' => ['nullable', 'date'],
            'closes_at' => ['nullable', 'date', 'after:opens_at'],
            'scheduled_at' => ['nullable', 'date'],
            'location' => ['nullable', 'string', 'max:180'],
            'story_id' => ['nullable', 'integer', 'exists:stories,id'],
        ]);

        $ama = $request->user()->hostedAmas()->create($data + ['status' => $data['status'] ?? 'open']);

        // A Story can naturally become an AMA, so keep the link pointing both ways.
        if (! empty($data['story_id'])) {
            Story::whereKey($data['story_id'])->update(['ama_id' => $ama->id]);
        }

        return response()->json(['data' => new AmaResource($ama->load('host'))], 201);
    }

    public function show(Request $request, Ama $ama): JsonResponse
    {
        $ama->load(['host', 'questions.user', 'questions.answers.user']);
        $ama->setRelation('questions', $ama->questions->sortByDesc('upvotes_count')->values());

        $voted = AmaQuestionVote::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('ama_question_id', $ama->questions->pluck('id'))
            ->pluck('ama_question_id')
            ->all();

        // A block body, not an arrow: `each` stops when the callback returns
        // false, which an arrow would do on the first un-voted question.
        $ama->questions->each(function (AmaQuestion $q) use ($voted) {
            $q->is_upvoted = in_array($q->id, $voted, true);
        });

        return response()->json(['data' => new AmaResource($ama)]);
    }

    public function update(Request $request, Ama $ama): JsonResponse
    {
        $this->assertHost($request, $ama);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:180'],
            'description' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'format' => ['sometimes', Rule::in(Ama::FORMATS)],
            'status' => ['sometimes', Rule::in(['draft', 'open', 'scheduled', 'closed'])],
            'opens_at' => ['sometimes', 'nullable', 'date'],
            'closes_at' => ['sometimes', 'nullable', 'date'],
            'scheduled_at' => ['sometimes', 'nullable', 'date'],
            'location' => ['sometimes', 'nullable', 'string', 'max:180'],
        ]);

        $ama->update($data);

        return response()->json(['data' => new AmaResource($ama->fresh()->load('host'))]);
    }

    public function destroy(Request $request, Ama $ama): JsonResponse
    {
        $this->assertHost($request, $ama);
        $ama->delete();

        return response()->json(['message' => 'AMA removed.']);
    }

    // --- Questions -----------------------------------------------------------

    public function askQuestion(Request $request, Ama $ama): JsonResponse
    {
        if (! in_array($ama->status, ['open', 'scheduled'], true)) {
            return response()->json(['message' => 'This AMA is not taking questions.'], 422);
        }

        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        $question = $ama->questions()->create($data + ['user_id' => $request->user()->id]);
        $ama->syncQuestionsCount();

        return response()->json([
            'data' => new AmaQuestionResource($question->load('user')),
        ], 201);
    }

    public function deleteQuestion(Request $request, AmaQuestion $question): JsonResponse
    {
        $user = $request->user();

        if ($question->user_id !== $user->id && $question->ama->host_id !== $user->id && ! $user->isAdmin()) {
            throw new AccessDeniedHttpException('You cannot remove this question.');
        }

        $ama = $question->ama;
        $question->delete();
        $ama->syncQuestionsCount();

        return response()->json(['message' => 'Question removed.']);
    }

    public function upvoteQuestion(Request $request, AmaQuestion $question): JsonResponse
    {
        $question->votes()->firstOrCreate(['user_id' => $request->user()->id]);
        $question->syncUpvotesCount();

        return $this->questionResponse($question->fresh(), true);
    }

    public function removeUpvote(Request $request, AmaQuestion $question): JsonResponse
    {
        $question->votes()->where('user_id', $request->user()->id)->delete();
        $question->syncUpvotesCount();

        return $this->questionResponse($question->fresh(), false);
    }

    /** Only the host answers - an AMA is "ask *me* anything". */
    public function answerQuestion(Request $request, AmaQuestion $question): JsonResponse
    {
        if ($question->ama->host_id !== $request->user()->id) {
            throw new AccessDeniedHttpException('Only the AMA host can answer.');
        }

        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);

        $answer = $question->answers()->create($data + ['user_id' => $request->user()->id]);

        return response()->json(['data' => new AmaAnswerResource($answer->load('user'))], 201);
    }

    protected function questionResponse(AmaQuestion $question, bool $isUpvoted): JsonResponse
    {
        $question->load(['user', 'answers.user']);
        $question->is_upvoted = $isUpvoted;

        return response()->json(['data' => new AmaQuestionResource($question)]);
    }

    protected function assertHost(Request $request, Ama $ama): void
    {
        if ($ama->host_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            throw new AccessDeniedHttpException('Only the host can manage this AMA.');
        }
    }
}
