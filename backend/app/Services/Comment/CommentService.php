<?php

namespace App\Services\Comment;

use App\Models\Comment;
use App\Models\Document;
use App\Models\User;
use App\Notifications\CommentPostedNotification;
use App\Services\ActivityLog\ActivityLogService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class CommentService
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

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

        $recipients = collect([$document->author_id, $document->owner_id])
            ->filter()
            ->unique()
            ->reject(fn ($id) => $id === $actor->id);

        if ($recipients->isNotEmpty()) {
            Notification::send(
                User::query()->whereIn('id', $recipients)->get(),
                new CommentPostedNotification($comment, $document)
            );
        }

        $this->activityLog->log(
            action: 'comment.created',
            user: $actor,
            subject: $document,
            description: "Commentaire sur {$document->reference}",
            properties: ['comment_id' => $comment->id],
        );

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
