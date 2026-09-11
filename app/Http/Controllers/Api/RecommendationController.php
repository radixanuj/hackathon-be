<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RecommendationResource;
use App\Models\Recommendation;
use App\Models\RecommendationLike;
use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Recommendation Corner: two streams, Work and Leisure, each with a "why". */
class RecommendationController extends Controller
{
    public function __construct(protected Notifier $notifier) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'stream' => ['nullable', Rule::in(Recommendation::STREAMS)],
            'type' => ['nullable', Rule::in(Recommendation::TYPES)],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'sort' => ['nullable', Rule::in(['recent', 'popular'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Recommendation::query()
            ->with('user')
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(
                fn ($sub) => $sub->where('title', 'like', '%'.$term.'%')
                    ->orWhere('creator', 'like', '%'.$term.'%')
                    ->orWhere('why', 'like', '%'.$term.'%')
            ))
            ->when($filters['stream'] ?? null, fn ($q, $stream) => $q->where('stream', $stream))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id));

        ($filters['sort'] ?? 'recent') === 'popular'
            ? $query->orderByDesc('likes_count')->orderByDesc('id')
            : $query->orderByDesc('id');

        $items = $query->paginate($filters['per_page'] ?? 20)->withQueryString();
        $this->attachLikeState($items, $request->user()->id);

        return RecommendationResource::collection($items);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'creator' => ['nullable', 'string', 'max:140'],
            'type' => ['required', Rule::in(Recommendation::TYPES)],
            'stream' => ['required', Rule::in(Recommendation::STREAMS)],
            'url' => ['nullable', 'url', 'max:500'],
            'why' => ['required', 'string', 'max:2000'],
        ]);

        $recommendation = $request->user()->recommendations()->create($data);

        return response()->json([
            'data' => new RecommendationResource($recommendation->load('user')),
        ], 201);
    }

    public function show(Request $request, Recommendation $recommendation): JsonResponse
    {
        $recommendation->load('user');
        $recommendation->is_liked = $recommendation->likes()->where('user_id', $request->user()->id)->exists();

        return response()->json(['data' => new RecommendationResource($recommendation)]);
    }

    public function update(Request $request, Recommendation $recommendation): JsonResponse
    {
        $this->assertOwner($request, $recommendation);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:180'],
            'creator' => ['sometimes', 'nullable', 'string', 'max:140'],
            'type' => ['sometimes', Rule::in(Recommendation::TYPES)],
            'stream' => ['sometimes', Rule::in(Recommendation::STREAMS)],
            'url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'why' => ['sometimes', 'string', 'max:2000'],
        ]);

        $recommendation->update($data);

        return response()->json([
            'data' => new RecommendationResource($recommendation->fresh()->load('user')),
        ]);
    }

    public function destroy(Request $request, Recommendation $recommendation): JsonResponse
    {
        $this->assertOwner($request, $recommendation);
        $recommendation->delete();

        return response()->json(['message' => 'Recommendation removed.']);
    }

    public function like(Request $request, Recommendation $recommendation): JsonResponse
    {
        $like = $recommendation->likes()->firstOrCreate(['user_id' => $request->user()->id]);
        $recommendation->syncLikesCount();

        // Liking, unliking and liking again should not notify three times.
        if ($like->wasRecentlyCreated) {
            $this->notifier->send($recommendation->user_id, 'recommendation.liked', [
                'actor' => $request->user(),
                'title' => $request->user()->name.' liked your recommendation',
                'body' => $recommendation->title,
                'subject' => $recommendation,
                'action_url' => '/community?tab=learn',
            ]);
        }

        return $this->show($request, $recommendation->fresh());
    }

    public function unlike(Request $request, Recommendation $recommendation): JsonResponse
    {
        $recommendation->likes()->where('user_id', $request->user()->id)->delete();
        $recommendation->syncLikesCount();

        return $this->show($request, $recommendation->fresh());
    }

    protected function assertOwner(Request $request, Recommendation $recommendation): void
    {
        if ($recommendation->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            throw new AccessDeniedHttpException('This recommendation is not yours.');
        }
    }

    /** One query for the whole page rather than one per row. */
    protected function attachLikeState($items, int $userId): void
    {
        $liked = RecommendationLike::query()
            ->where('user_id', $userId)
            ->whereIn('recommendation_id', $items->pluck('id'))
            ->pluck('recommendation_id')
            ->all();

        // A block body, not an arrow: `each` stops when the callback returns
        // false, which an arrow would do on the first un-liked row.
        $items->each(function (Recommendation $r) use ($liked) {
            $r->is_liked = in_array($r->id, $liked, true);
        });
    }
}
