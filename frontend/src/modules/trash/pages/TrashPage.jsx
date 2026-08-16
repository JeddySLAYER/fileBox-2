import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { RotateCcw, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { useConfirm } from '@/components/ConfirmDialog'
import Button from '@/components/ui/Button'
import EmptyState from '@/components/ui/EmptyState'
import LoadingScreen from '@/components/ui/LoadingScreen'
import PageHeader from '@/components/ui/PageHeader'
import Tabs from '@/components/ui/Tabs'
import { getErrorMessage } from '@/lib/api'
import { unwrapList, unwrapPaginated } from '@/lib/apiHelpers'
import { formatDate, statusLabel } from '@/lib/format'
import { can } from '@/lib/permissions'
import { queryKeys } from '@/lib/queryClient'
import { documentsApi } from '@/modules/documents/api'
import { foldersApi } from '@/modules/folders/api'
import { trashApi } from '@/modules/trash/api'
import { useAuthStore } from '@/stores/authStore'

export default function TrashPage() {
  const user = useAuthStore((s) => s.user)
  const queryClient = useQueryClient()
  const confirm = useConfirm()
  const [tab, setTab] = useState('documents')

  const tabs = [
    { id: 'documents', label: 'Documents', show: can(user, 'documents.view') },
    { id: 'folders', label: 'Dossiers', show: can(user, 'folders.view') },
  ].filter((t) => t.show)

  const docsQuery = useQuery({
    queryKey: queryKeys.documents({ trashed: true }),
    queryFn: () => documentsApi.list({ trashed: 1, per_page: 50 }),
    enabled: tab === 'documents',
  })

  const foldersQuery = useQuery({
    queryKey: queryKeys.folders({ trashed: true }),
    queryFn: () => foldersApi.list({ trashed: 1 }),
    enabled: tab === 'folders',
  })

  function invalidateTrash() {
    queryClient.invalidateQueries({ queryKey: ['documents'] })
    queryClient.invalidateQueries({ queryKey: ['folders'] })
    queryClient.invalidateQueries({ queryKey: queryKeys.dashboard })
  }

  const restoreDoc = useMutation({
    mutationFn: (id) => documentsApi.restore(id),
    onSuccess: (res) => {
      toast.success(res.message)
      invalidateTrash()
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const restoreFolder = useMutation({
    mutationFn: (id) => foldersApi.restore(id),
    onSuccess: (res) => {
      toast.success(res.message)
      invalidateTrash()
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const forceDoc = useMutation({
    mutationFn: (id) => documentsApi.forceRemove(id),
    onSuccess: (res) => {
      toast.success(res.message)
      invalidateTrash()
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const forceFolder = useMutation({
    mutationFn: (id) => foldersApi.forceRemove(id),
    onSuccess: (res) => {
      toast.success(res.message)
      invalidateTrash()
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const emptyTrash = useMutation({
    mutationFn: () => trashApi.empty(),
    onSuccess: (res) => {
      toast.success(res.message)
      invalidateTrash()
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const documents = unwrapPaginated(docsQuery.data).data
  const folders = unwrapList(foldersQuery.data)
  const canEmpty = can(user, 'documents.delete') || can(user, 'folders.delete')

  async function confirmEmpty() {
    const ok = await confirm({
      title: 'Vider la corbeille',
      description:
        'Tous les éléments visibles dans votre corbeille seront supprimés définitivement. Cette action est irréversible. Les fichiers sont aussi purgés automatiquement après 30 jours.',
      confirmLabel: 'Vider la corbeille',
      tone: 'danger',
    })
    if (ok) emptyTrash.mutate()
  }

  async function confirmForceDoc(doc) {
    const ok = await confirm({
      title: 'Supprimer définitivement',
      description: `« ${doc.title} » sera définitivement supprimé, y compris ses fichiers. Irréversible.`,
      confirmLabel: 'Supprimer',
      tone: 'danger',
    })
    if (ok) forceDoc.mutate(doc.id)
  }

  async function confirmForceFolder(folder) {
    const ok = await confirm({
      title: 'Supprimer définitivement',
      description: `« ${folder.name} » et son contenu seront définitivement supprimés. Irréversible.`,
      confirmLabel: 'Supprimer',
      tone: 'danger',
    })
    if (ok) forceFolder.mutate(folder.id)
  }

  if (tabs.length === 0) {
    return <EmptyState title="Accès refusé" description="Aucune permission de corbeille." />
  }

  return (
    <>
      <PageHeader
        title="Corbeille"
        description="Restaurez un élément ou supprimez-le définitivement. Les fichiers sont purgés automatiquement après 30 jours."
        actions={
          canEmpty ? (
            <Button
              size="sm"
              variant="danger"
              disabled={emptyTrash.isPending}
              onClick={confirmEmpty}
            >
              <Trash2 className="h-4 w-4" />
              Vider la corbeille
            </Button>
          ) : null
        }
      />

      <Tabs
        tabs={tabs}
        active={tabs.some((t) => t.id === tab) ? tab : tabs[0].id}
        onChange={setTab}
      />

      <div className="mt-6">
        {tab === 'documents' ? (
          docsQuery.isLoading ? (
            <LoadingScreen />
          ) : documents.length === 0 ? (
            <EmptyState title="Aucun document en corbeille" />
          ) : (
            <ul className="divide-y divide-border rounded-xl border border-border bg-background">
              {documents.map((doc) => (
                <li key={doc.id} className="flex items-center justify-between gap-3 px-4 py-3">
                  <div>
                    <Link to={`/documents/${doc.id}`} className="font-medium hover:text-primary">
                      {doc.title}
                    </Link>
                    <p className="text-xs text-muted-foreground">
                      {doc.reference} · {statusLabel(doc.status)} ·{' '}
                      {formatDate(doc.deleted_at, true)}
                    </p>
                  </div>
                  <div className="flex shrink-0 gap-2">
                    <Button size="sm" variant="secondary" onClick={() => restoreDoc.mutate(doc.id)}>
                      <RotateCcw className="h-4 w-4" />
                      Restaurer
                    </Button>
                    {can(user, 'documents.delete') ? (
                      <Button size="sm" variant="danger" onClick={() => confirmForceDoc(doc)}>
                        <Trash2 className="h-4 w-4" />
                        Supprimer
                      </Button>
                    ) : null}
                  </div>
                </li>
              ))}
            </ul>
          )
        ) : null}

        {tab === 'folders' ? (
          foldersQuery.isLoading ? (
            <LoadingScreen />
          ) : folders.length === 0 ? (
            <EmptyState title="Aucun dossier en corbeille" />
          ) : (
            <ul className="divide-y divide-border rounded-xl border border-border bg-background">
              {folders.map((folder) => (
                <li key={folder.id} className="flex items-center justify-between gap-3 px-4 py-3">
                  <div>
                    <p className="font-medium">{folder.name}</p>
                    <p className="text-xs text-muted-foreground">
                      {formatDate(folder.deleted_at, true)}
                    </p>
                  </div>
                  <div className="flex shrink-0 gap-2">
                    <Button
                      size="sm"
                      variant="secondary"
                      onClick={() => restoreFolder.mutate(folder.id)}
                    >
                      <RotateCcw className="h-4 w-4" />
                      Restaurer
                    </Button>
                    {can(user, 'folders.delete') ? (
                      <Button size="sm" variant="danger" onClick={() => confirmForceFolder(folder)}>
                        <Trash2 className="h-4 w-4" />
                        Supprimer
                      </Button>
                    ) : null}
                  </div>
                </li>
              ))}
            </ul>
          )
        ) : null}
      </div>
    </>
  )
}
