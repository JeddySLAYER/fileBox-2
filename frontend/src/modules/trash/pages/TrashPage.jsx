import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { RotateCcw } from 'lucide-react'
import { toast } from 'sonner'
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
import { usersApi } from '@/modules/users/api'
import { useAuthStore } from '@/stores/authStore'

export default function TrashPage() {
  const user = useAuthStore((s) => s.user)
  const queryClient = useQueryClient()
  const [tab, setTab] = useState('documents')

  const tabs = [
    { id: 'documents', label: 'Documents', show: can(user, 'documents.view') },
    { id: 'folders', label: 'Dossiers', show: can(user, 'folders.view') },
    { id: 'users', label: 'Utilisateurs', show: can(user, 'users.view') },
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

  const usersQuery = useQuery({
    queryKey: queryKeys.users({ trashed: true }),
    queryFn: () => usersApi.list({ trashed: 1, per_page: 50 }),
    enabled: tab === 'users',
  })

  const restoreDoc = useMutation({
    mutationFn: (id) => documentsApi.restore(id),
    onSuccess: (res) => {
      toast.success(res.message)
      queryClient.invalidateQueries({ queryKey: ['documents'] })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const restoreFolder = useMutation({
    mutationFn: (id) => foldersApi.restore(id),
    onSuccess: (res) => {
      toast.success(res.message)
      queryClient.invalidateQueries({ queryKey: ['folders'] })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const restoreUser = useMutation({
    mutationFn: (id) => usersApi.restore(id),
    onSuccess: (res) => {
      toast.success(res.message)
      queryClient.invalidateQueries({ queryKey: ['users'] })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const documents = unwrapPaginated(docsQuery.data).data
  const folders = unwrapList(foldersQuery.data)
  const users = unwrapPaginated(usersQuery.data).data

  if (tabs.length === 0) {
    return <EmptyState title="Accès refusé" description="Aucune permission de corbeille." />
  }

  return (
    <>
      <PageHeader
        title="Corbeille"
        description="Restauration soft-delete (trashed=1)."
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
                  <Button size="sm" variant="secondary" onClick={() => restoreDoc.mutate(doc.id)}>
                    <RotateCcw className="h-4 w-4" />
                    Restaurer
                  </Button>
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
                  <Button
                    size="sm"
                    variant="secondary"
                    onClick={() => restoreFolder.mutate(folder.id)}
                  >
                    <RotateCcw className="h-4 w-4" />
                    Restaurer
                  </Button>
                </li>
              ))}
            </ul>
          )
        ) : null}

        {tab === 'users' ? (
          usersQuery.isLoading ? (
            <LoadingScreen />
          ) : users.length === 0 ? (
            <EmptyState title="Aucun utilisateur en corbeille" />
          ) : (
            <ul className="divide-y divide-border rounded-xl border border-border bg-background">
              {users.map((u) => (
                <li key={u.id} className="flex items-center justify-between gap-3 px-4 py-3">
                  <div>
                    <p className="font-medium">{u.name}</p>
                    <p className="text-xs text-muted-foreground">{u.email}</p>
                  </div>
                  <Button size="sm" variant="secondary" onClick={() => restoreUser.mutate(u.id)}>
                    <RotateCcw className="h-4 w-4" />
                    Restaurer
                  </Button>
                </li>
              ))}
            </ul>
          )
        ) : null}
      </div>
    </>
  )
}
