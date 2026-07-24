import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Archive, Download, RotateCcw, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import RequirePermission from '@/components/RequirePermission'
import Badge from '@/components/ui/Badge'
import Button from '@/components/ui/Button'
import Card from '@/components/ui/Card'
import EmptyState from '@/components/ui/EmptyState'
import Input from '@/components/ui/Input'
import Label from '@/components/ui/Label'
import LoadingScreen from '@/components/ui/LoadingScreen'
import PageHeader from '@/components/ui/PageHeader'
import { getErrorMessage } from '@/lib/api'
import { unwrapList } from '@/lib/apiHelpers'
import { formatBytes, formatDate } from '@/lib/format'
import { queryKeys } from '@/lib/queryClient'
import { backupsApi } from '@/modules/backups/api'

export default function BackupsPage() {
  const queryClient = useQueryClient()
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

  return (
    <RequirePermission permission="settings.manage">
      <PageHeader
        title="Sauvegardes"
        description="Archives JSON + fichiers (API /backups). Permission settings.manage."
      />

      <Card className="mb-6">
        <Label htmlFor="notes">Notes (optionnel)</Label>
        <div className="mt-1 flex flex-wrap gap-2">
          <Input
            id="notes"
            className="max-w-md"
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            placeholder="Ex. avant déploiement"
          />
          <Button onClick={() => createBackup.mutate()} disabled={createBackup.isPending}>
            <Archive className="h-4 w-4" />
            Créer une sauvegarde
          </Button>
        </div>
      </Card>

      {isLoading ? (
        <LoadingScreen />
      ) : backups.length === 0 ? (
        <EmptyState title="Aucune sauvegarde" />
      ) : (
        <div className="grid gap-3">
          {backups.map((backup) => (
            <Card key={backup.id} className="flex flex-wrap items-center justify-between gap-3">
              <div>
                <div className="flex items-center gap-2">
                  <p className="font-medium">{backup.name}</p>
                  <Badge tone={backup.status === 'completed' ? 'success' : 'neutral'}>
                    {backup.status}
                  </Badge>
                </div>
                <p className="mt-1 text-xs text-muted-foreground">
                  {formatBytes(backup.size)} · {formatDate(backup.created_at, true)}
                  {backup.creator?.name ? ` · ${backup.creator.name}` : ''}
                  {backup.restored_at
                    ? ` · restaurée ${formatDate(backup.restored_at, true)}`
                    : ''}
                </p>
                {backup.notes ? (
                  <p className="mt-1 text-sm text-muted-foreground">{backup.notes}</p>
                ) : null}
              </div>
              <div className="flex gap-1">
                <Button
                  size="sm"
                  variant="secondary"
                  onClick={async () => {
                    try {
                      await backupsApi.download(backup.id, `${backup.name}.zip`)
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
                  onClick={() => {
                    if (
                      window.confirm(
                        'Restaurer cette sauvegarde ? Les données actuelles seront remplacées.',
                      )
                    ) {
                      restoreBackup.mutate(backup.id)
                    }
                  }}
                >
                  <RotateCcw className="h-4 w-4" />
                </Button>
                <Button
                  size="sm"
                  variant="ghost"
                  onClick={() => {
                    if (window.confirm('Supprimer cette sauvegarde ?')) {
                      removeBackup.mutate(backup.id)
                    }
                  }}
                >
                  <Trash2 className="h-4 w-4" />
                </Button>
              </div>
            </Card>
          ))}
        </div>
      )}
    </RequirePermission>
  )
}
