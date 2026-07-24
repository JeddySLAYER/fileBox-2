<?php

namespace App\Http\Controllers\Api\Tag;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tag\StoreTagRequest;
use App\Http\Requests\Tag\SyncDocumentTagsRequest;
use App\Http\Requests\Tag\UpdateTagRequest;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\TagResource;
use App\Models\Document;
use App\Models\Tag;
use App\Services\Tag\TagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TagController extends Controller
{
    public function __construct(
        private readonly TagService $tagService,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Tag::class);

        return TagResource::collection($this->tagService->list());
    }

    public function store(StoreTagRequest $request): JsonResponse
    {
        $this->authorize('create', Tag::class);

        $tag = $this->tagService->create($request->validated());

        return response()->json([
            'message' => 'Tag créé.',
            'tag' => new TagResource($tag),
        ], 201);
    }

    public function show(Tag $tag): JsonResponse
    {
        $this->authorize('view', $tag);

        $tag->loadCount('documents');

        return response()->json(['tag' => new TagResource($tag)]);
    }

    public function update(UpdateTagRequest $request, Tag $tag): JsonResponse
    {
        $this->authorize('update', $tag);

        $tag = $this->tagService->update($tag, $request->validated());

        return response()->json([
            'message' => 'Tag mis à jour.',
            'tag' => new TagResource($tag),
        ]);
    }

    public function destroy(Tag $tag): JsonResponse
    {
        $this->authorize('delete', $tag);

        $this->tagService->delete($tag);

        return response()->json(['message' => 'Tag supprimé.']);
    }

    public function syncDocumentTags(SyncDocumentTagsRequest $request, Document $document): JsonResponse
    {
        $this->authorize('update', $document);

        $document = $this->tagService->syncDocumentTags($document, $request->validated('tag_ids'));

        return response()->json([
            'message' => 'Tags du document synchronisés.',
            'document' => new DocumentResource($document),
        ]);
    }
}
