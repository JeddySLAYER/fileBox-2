import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { ArchiveRestore } from 'lucide-react'
import { toast } from 'sonner'
import Button from '@/components/ui/Button'
import EmptyState from '@/components/ui/EmptyState'
import LoadingScreen from '@/components/ui/LoadingScreen'
import PageHeader from '@/components/ui/PageHeader'
import { getErrorMessage } from '@/lib/api'
import { unwrapPaginated } from '@/lib/apiHelpers'
import { formatDate, statusLabel } from '@/lib/format'
import { queryKeys } from '@/lib/queryClient'
import { documentsApi } from '@/modules/documents/api'

export default function ArchivesPage() {
  const queryClient = useQueryClient()

  const docsQuery = useQuery({
    queryKey: queryKeys.documents({ archived: true }),
    queryFn: () => documentsApi.list({ archived: 1, per_page: 50 }),
  })

  const unarchive = useMutation({
    mutationFn: (id) => documentsApi.unarchive(id),
    onSuccess: (res) => {
      toast.success(res.message)
      queryClient.invalidateQueries({ queryKey: ['documents'] })
      queryClient.invalidateQueries({ queryKey: queryKeys.dashboard })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const documents = unwrapPaginated(docsQuery.data).data

  return (
    <>
      <PageHeader
        title="Archives"
        description="Documents gelés : consultation et téléchargement uniquement. Désarchiver les renvoie dans l’explorateur en brouillon."
      />

      {docsQuery.isLoading ? (
        <LoadingScreen />
      ) : documents.length === 0 ? (
        <EmptyState
          title="Aucune archive"
          description="Archiver un document depuis l’explorateur ou sa fiche le déplace ici."
        />
      ) : (
        <ul className="divide-y divide-border rounded-xl border border-border bg-background">
          {documents.map((doc) => (
            <li key={doc.id} className="flex items-center justify-between gap-3 px-4 py-3">
              <div className="min-w-0">
                <Link to={`/documents/${doc.id}`} className="font-medium hover:text-primary">
                  {doc.title}
                </Link>
                <p className="truncate text-xs text-muted-foreground">
                  {doc.reference} · {doc.folder?.name ?? '—'} · {statusLabel(doc.status)}
                  {doc.archived_at ? ` · ${formatDate(doc.archived_at, true)}` : ''}
                </p>
              </div>
              <Button
                size="sm"
                variant="secondary"
                disabled={unarchive.isPending}
                onClick={() => unarchive.mutate(doc.id)}
              >
                <ArchiveRestore className="h-4 w-4" />
                Désarchiver
              </Button>
            </li>
          ))}
        </ul>
      )}
    </>
  )
}
