import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { MessageSquare, Send, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { useConfirm } from '@/components/ConfirmDialog'
import Button from '@/components/ui/Button'
import EmptyState from '@/components/ui/EmptyState'
import Input from '@/components/ui/Input'
import LoadingScreen from '@/components/ui/LoadingScreen'
import { unwrapList } from '@/lib/apiHelpers'
import { getErrorMessage } from '@/lib/api'
import { formatDate } from '@/lib/format'
import { can } from '@/lib/permissions'
import { queryKeys } from '@/lib/queryClient'
import { commentsApi } from '@/modules/comments/api'
import { useAuthStore } from '@/stores/authStore'

export default function DocumentCommentsPanel({ documentId }) {
  const user = useAuthStore((s) => s.user)
  const queryClient = useQueryClient()
  const confirm = useConfirm()
  const [content, setContent] = useState('')

  const { data, isLoading } = useQuery({
    queryKey: queryKeys.documentComments(documentId),
    queryFn: () => commentsApi.listForDocument(documentId),
  })

  const createComment = useMutation({
    mutationFn: () => commentsApi.create(documentId, { content }),
    onSuccess: (res) => {
      toast.success(res.message)
      setContent('')
      queryClient.invalidateQueries({ queryKey: queryKeys.documentComments(documentId) })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const removeComment = useMutation({
    mutationFn: (commentId) => commentsApi.remove(commentId),
    onSuccess: (res) => {
      toast.success(res.message)
      queryClient.invalidateQueries({ queryKey: queryKeys.documentComments(documentId) })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const comments = unwrapList(data)
  const canCreate = can(user, 'comments.create')

  if (isLoading) return <LoadingScreen label="Commentaires…" />

  return (
    <div>
      {canCreate ? (
        <form
          className="mb-4 flex gap-2"
          onSubmit={(e) => {
            e.preventDefault()
            if (!content.trim()) return
            createComment.mutate()
          }}
        >
          <Input
            placeholder="Ajouter un commentaire…"
            value={content}
            onChange={(e) => setContent(e.target.value)}
          />
          <Button type="submit" disabled={createComment.isPending}>
            <Send className="h-4 w-4" />
          </Button>
        </form>
      ) : (
        <p className="mb-4 text-xs text-muted-foreground">
          Permission comments.create requise pour commenter.
        </p>
      )}

      {comments.length === 0 ? (
        <EmptyState title="Aucun commentaire" description="Soyez le premier à commenter ce document." />
      ) : (
        <ul className="space-y-3">
          {comments.map((comment) => (
            <li key={comment.id} className="rounded-lg border border-border p-3">
              <div className="flex items-start justify-between gap-2">
                <div className="flex items-center gap-2">
                  <MessageSquare className="h-4 w-4 text-muted-foreground" />
                  <span className="text-sm font-medium">{comment.user?.name ?? 'Utilisateur'}</span>
                  <span className="text-xs text-muted-foreground">
                    {formatDate(comment.created_at, true)}
                  </span>
                </div>
                  {(comment.user?.id === user?.id || can(user, 'documents.delete')) && (
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={async () => {
                      const ok = await confirm({
                        title: 'Supprimer le commentaire',
                        description: 'Supprimer ce commentaire ?',
                        confirmLabel: 'Supprimer',
                      })
                      if (ok) removeComment.mutate(comment.id)
                    }}
                  >
                    <Trash2 className="h-3.5 w-3.5" />
                  </Button>
                )}
              </div>
              <p className="mt-2 text-sm whitespace-pre-wrap">{comment.content}</p>
              {(comment.replies ?? []).length > 0 ? (
                <ul className="mt-3 space-y-2 border-l-2 border-border pl-3">
                  {comment.replies.map((reply) => (
                    <li key={reply.id} className="text-sm">
                      <span className="font-medium">{reply.user?.name}</span>
                      <span className="ml-2 text-xs text-muted-foreground">
                        {formatDate(reply.created_at, true)}
                      </span>
                      <p className="mt-1 text-muted-foreground">{reply.content}</p>
                    </li>
                  ))}
                </ul>
              ) : null}
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
