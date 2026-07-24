<?php

namespace App\Http\Controllers\Api\Comment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Comment\StoreCommentRequest;
use App\Http\Requests\Comment\UpdateCommentRequest;
use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Models\Document;
use App\Services\Comment\CommentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CommentController extends Controller
{
    public function __construct(
        private readonly CommentService $commentService,
    ) {}

    public function index(Document $document): AnonymousResourceCollection
    {
        $this->authorize('view', $document);

        return CommentResource::collection(
            $this->commentService->listForDocument($document)
        );
    }

    public function store(StoreCommentRequest $request, Document $document): JsonResponse
    {
        $this->authorize('create', Comment::class);
        $this->authorize('view', $document);

        $comment = $this->commentService->create(
            $document,
            $request->user(),
            $request->validated(),
        );

        return response()->json([
            'message' => 'Commentaire ajouté.',
            'comment' => new CommentResource($comment),
        ], 201);
    }

    public function update(UpdateCommentRequest $request, Comment $comment): JsonResponse
    {
        $this->authorize('update', $comment);

        $comment = $this->commentService->update($comment, $request->validated('content'));

        return response()->json([
            'message' => 'Commentaire mis à jour.',
            'comment' => new CommentResource($comment),
        ]);
    }

    public function destroy(Comment $comment): JsonResponse
    {
        $this->authorize('delete', $comment);

        $this->commentService->delete($comment);

        return response()->json(['message' => 'Commentaire supprimé.']);
    }
}
