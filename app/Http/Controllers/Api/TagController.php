<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class TagController extends Controller
{
    /** Powers the tag autocomplete on profile editing and people search. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:60'],
            'type' => ['nullable', Rule::in(Tag::TYPES)],
            // The curated Pick a Brain row, and a single-slug lookup so a screen
            // holding a slug can name it without paging the whole vocabulary.
            'featured' => ['nullable', 'boolean'],
            'slug' => ['nullable', 'string', 'max:80'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $featured = $request->boolean('featured');

        $tags = Tag::query()
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where('name', 'like', '%'.$term.'%'))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['slug'] ?? null, fn ($q, $slug) => $q->where('slug', $slug))
            // Featured topics carry their own running order; everything else falls
            // back to how often it turns up on a profile.
            ->when($featured, fn ($q) => $q->featured())
            ->unless($featured, fn ($q) => $q->orderByDesc('usage_count')->orderBy('name'))
            ->limit($filters['limit'] ?? 50)
            ->get();

        return TagResource::collection($tags);
    }

    public function store(Request $request): TagResource
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'type' => ['required', Rule::in(Tag::TYPES)],
        ]);

        return new TagResource(Tag::findOrCreateByName($data['name'], $data['type']));
    }
}
