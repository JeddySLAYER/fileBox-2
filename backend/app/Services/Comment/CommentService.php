<?php

namespace App\Services\Comment;

use App\Events\Comment\CommentPosted;
use App\Models\Comment;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class CommentService
{
    public function listForDocument(Document $document): Collection
    {
        return Comment::query()
            ->where('document_id', $document->id)
            ->whereNull('parent_id')
            ->with(['user', 'replies.user'])
            ->latest()
            ->get();
    }

    /**
     * @param  array{content: string, parent_id?: int|null}  $data
     */
    public function create(Document $document, User $actor, array $data): Comment
    {
        if (! empty($data['parent_id'])) {
            $parent = Comment::query()->findOrFail($data['parent_id']);
            if ($parent->document_id !== $document->id) {
                throw ValidationException::withMessages([
                    'parent_id' => ['Le commentaire parent n\'appartient pas à ce document.'],
                ]);
            }
        }

        $comment = Comment::query()->create([
            'document_id' => $document->id,
            'user_id' => $actor->id,
            'parent_id' => $data['parent_id'] ?? null,
            'content' => $data['content'],
        ])->load(['user', 'replies.user']);

        event(new CommentPosted($comment, $document, $actor));

        return $comment;
    }

    public function update(Comment $comment, string $content): Comment
    {
        $comment->content = $content;
        $comment->save();

        return $comment->load(['user', 'replies.user']);
    }

    public function delete(Comment $comment): void
    {
        $comment->delete();
    }
}
