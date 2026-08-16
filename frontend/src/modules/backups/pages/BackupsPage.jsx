import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Archive, Download, RotateCcw, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { useConfirm } from '@/components/ConfirmDialog'
import RequirePermission from '@/components/RequirePermission'
import Badge from '@/components/ui/Badge'
import Button from '@/components/ui/Button'
import DataTable from '@/components/ui/DataTable'
import Input from '@/components/ui/Input'
import Label from '@/components/ui/Label'
import Modal from '@/components/ui/Modal'
import PageHeader from '@/components/ui/PageHeader'
import { getErrorMessage } from '@/lib/api'
import { unwrapList, paginateClient, PAGE_SIZE } from '@/lib/apiHelpers'
import { formatBytes, formatDate } from '@/lib/format'
import { queryKeys } from '@/lib/queryClient'
import { backupsApi } from '@/modules/backups/api'

export default function BackupsPage() {
  const queryClient = useQueryClient()
  const confirm = useConfirm()
  const [page, setPage] = useState(1)
  const [showForm, setShowForm] = useState(false)
  const [notes, setNotes] = useState('')

  const { data, isLoading } = useQuery({
    queryKey: queryKeys.backups,
    queryFn: backupsApi.list,
  })

  const createBackup = useMutation({
    mutationFn: () => backupsApi.create(notes || undefined),
    onSuccess: (res) => {
      toast.success(res.message)
      setNotes('')
      setShowForm(false)
      queryClient.invalidateQueries({ queryKey: queryKeys.backups })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const restoreBackup = useMutation({
    mutationFn: (id) => backupsApi.restore(id),
    onSuccess: (res) => {
      toast.success(res.message)
      queryClient.invalidateQueries({ queryKey: queryKeys.backups })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const removeBackup = useMutation({
    mutationFn: (id) => backupsApi.remove(id),
    onSuccess: (res) => {
      toast.success(res.message)
      queryClient.invalidateQueries({ queryKey: queryKeys.backups })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const backups = unwrapList(data)
  const { data: pageRows, meta } = useMemo(
    () => paginateClient(backups, page, PAGE_SIZE),
    [backups, page],
  )

  const columns = [
    {
      key: 'name',
      header: 'Sauvegarde',
      cell: (b) => (
        <div>
          <p className="font-medium">{b.name}</p>
          {b.notes ? (
            <p className="mt-0.5 line-clamp-1 text-xs text-muted-foreground">{b.notes}</p>
          ) : null}
        </div>
      ),
    },
    {
      key: 'status',
      header: 'Statut',
      cell: (b) => (
        <Badge tone={b.status === 'completed' ? 'success' : 'neutral'}>{b.status}</Badge>
      ),
    },
    {
      key: 'size',
      header: 'Taille',
      cell: (b) => formatBytes(b.size),
    },
    {
      key: 'creator',
      header: 'Auteur',
      cell: (b) => b.creator?.name ?? '—',
    },
    {
      key: 'created',
      header: 'Créée',
      cell: (b) => (
        <span className="text-xs text-muted-foreground">{formatDate(b.created_at, true)}</span>
      ),
    },
    {
      key: 'actions',
      header: 'Actions',
      className: 'w-[1%] whitespace-nowrap',
      cell: (b) => (
        <div className="flex gap-1">
          <Button
            size="sm"
            variant="secondary"
            title="Télécharger"
            onClick={async () => {
              try {
                await backupsApi.download(b.id, `${b.name}.zip`)
              } catch (e) {
                toast.error(getErrorMessage(e, 'Téléchargement impossible.'))
              }
            }}
          >
            <Download className="h-4 w-4" />
          </Button>
          <Button
            size="sm"
            variant="secondary"
            title="Restaurer"
            onClick={async () => {
              const ok = await confirm({
                title: 'Restaurer la sauvegarde',
                description:
                  'Restaurer cette sauvegarde ? Les données actuelles seront remplacées.',
                confirmLabel: 'Restaurer',
                tone: 'danger',
              })
              if (ok) restoreBackup.mutate(b.id)
            }}
          >
            <RotateCcw className="h-4 w-4" />
          </Button>
          <Button
            size="sm"
            variant="ghost"
            title="Supprimer"
            onClick={async () => {
              const ok = await confirm({
                title: 'Supprimer la sauvegarde',
                description: 'Supprimer cette sauvegarde ?',
                confirmLabel: 'Supprimer',
              })
              if (ok) removeBackup.mutate(b.id)
            }}
          >
            <Trash2 className="h-4 w-4" />
          </Button>
        </div>
      ),
    },
  ]

  return (
    <RequirePermission permission="settings.manage">
      <PageHeader
        title="Sauvegardes"
        description="Copie complète de la plateforme (données + fichiers). Utile avant une opération risquée. Restaurer remplace l’état actuel par celui de l’archive. Les copies de plus de 30 jours sont retirées automatiquement."
        actions={
          <Button size="sm" onClick={() => setShowForm(true)}>
            <Archive className="h-4 w-4" />
            Nouvelle sauvegarde
          </Button>
        }
      />

      <DataTable
        columns={columns}
        rows={pageRows}
        loading={isLoading}
        emptyTitle="Aucune sauvegarde"
        meta={meta}
        onPageChange={setPage}
      />

      <Modal
        open={showForm}
        onClose={() => {
          setShowForm(false)
          setNotes('')
        }}
        title="Créer une sauvegarde"
        size="sm"
        footer={
          <>
            <Button
              type="button"
              variant="secondary"
              onClick={() => {
                setShowForm(false)
                setNotes('')
              }}
            >
              Annuler
            </Button>
            <Button
              type="button"
              disabled={createBackup.isPending}
              onClick={() => createBackup.mutate()}
            >
              Créer
            </Button>
          </>
        }
      >
        <Label htmlFor="notes">Notes (optionnel)</Label>
        <Input
          id="notes"
          value={notes}
          onChange={(e) => setNotes(e.target.value)}
          placeholder="Ex. avant déploiement"
        />
      </Modal>
    </RequirePermission>
  )
}
