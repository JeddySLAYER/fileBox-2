import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { ChevronRight, FilePlus2, FolderPlus, Trash2, Upload } from 'lucide-react'
import { toast } from 'sonner'
import Badge from '@/components/ui/Badge'
import Button from '@/components/ui/Button'
import EmptyState from '@/components/ui/EmptyState'
import Input from '@/components/ui/Input'
import Label from '@/components/ui/Label'
import LoadingScreen from '@/components/ui/LoadingScreen'
import PageHeader from '@/components/ui/PageHeader'
import { getErrorMessage } from '@/lib/api'
import { unwrapList, unwrapPaginated } from '@/lib/apiHelpers'
import { formatDate, statusLabel } from '@/lib/format'
import { can } from '@/lib/permissions'
import { queryKeys } from '@/lib/queryClient'
import { documentsApi } from '@/modules/documents/api'
import { foldersApi } from '@/modules/folders/api'
import { useAuthStore } from '@/stores/authStore'

function statusTone(status) {
  if (status === 'valide') return 'success'
  if (status === 'en_validation') return 'warning'
  if (status === 'rejete') return 'danger'
  return 'neutral'
}

export default function ExplorerPage() {
  const user = useAuthStore((s) => s.user)
  const queryClient = useQueryClient()
  const [params, setParams] = useSearchParams()
  const folderId = params.get('folder') ? Number(params.get('folder')) : null

  const [showFolderForm, setShowFolderForm] = useState(false)
  const [showDocForm, setShowDocForm] = useState(false)
  const [folderName, setFolderName] = useState('')
  const [docTitle, setDocTitle] = useState('')
  const [docFile, setDocFile] = useState(null)

  const folderFilters = useMemo(() => {
    if (folderId == null) return {}
    return { parent_id: folderId }
  }, [folderId])

  const docFilters = useMemo(() => {
    if (folderId == null) return { per_page: 50 }
    return { folder_id: folderId, per_page: 50 }
  }, [folderId])

  const foldersQuery = useQuery({
    queryKey: queryKeys.folders(folderFilters),
    queryFn: () => foldersApi.list(folderFilters),
  })

  const documentsQuery = useQuery({
    queryKey: queryKeys.documents(docFilters),
    queryFn: () => documentsApi.list(docFilters),
  })

  const createFolder = useMutation({
    mutationFn: () =>
      foldersApi.create({
        name: folderName,
        parent_id: folderId,
      }),
    onSuccess: () => {
      toast.success('Dossier créé')
      setFolderName('')
      setShowFolderForm(false)
      queryClient.invalidateQueries({ queryKey: ['folders'] })
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  const createDocument = useMutation({
    mutationFn: () => {
      if (!folderId) {
        throw new Error('Sélectionnez un dossier avant d’uploader.')
      }
      const form = new FormData()
      form.append('title', docTitle)
      form.append('folder_id', String(folderId))
      form.append('file', docFile)
      return documentsApi.create(form)
    },
    onSuccess: () => {
      toast.success('Document créé')
      setDocTitle('')
      setDocFile(null)
      setShowDocForm(false)
      queryClient.invalidateQueries({ queryKey: ['documents'] })
    },
    onError: (error) => toast.error(getErrorMessage(error, error.message)),
  })

  const folders = unwrapList(foldersQuery.data)
  const documents = unwrapPaginated(documentsQuery.data).data

  const openFolder = (id) => {
    const next = new URLSearchParams(params)
    if (id == null) next.delete('folder')
    else next.set('folder', String(id))
    setParams(next)
  }

  if (foldersQuery.isLoading || documentsQuery.isLoading) {
    return <LoadingScreen />
  }

  return (
    <>
      <PageHeader
        title="Explorateur"
        description="Parcourez dossiers et documents. OCR / IA : phase suivante."
        actions={
          <>
            <Button as={Link} to="/trash" variant="ghost" size="sm">
              <Trash2 className="h-4 w-4" />
              Corbeille
            </Button>
            {can(user, 'folders.create') ? (
              <Button variant="secondary" size="sm" onClick={() => setShowFolderForm((v) => !v)}>
                <FolderPlus className="h-4 w-4" />
                Nouveau dossier
              </Button>
            ) : null}
            {can(user, 'documents.create') ? (
              <Button
                size="sm"
                onClick={() => setShowDocForm((v) => !v)}
                disabled={!folderId}
                title={!folderId ? 'Ouvrez un dossier pour uploader' : undefined}
              >
                <FilePlus2 className="h-4 w-4" />
                Nouveau document
              </Button>
            ) : null}
          </>
        }
      />

      <div className="mb-4 flex flex-wrap items-center gap-1 text-xs text-muted-foreground">
        <button type="button" className="hover:text-primary" onClick={() => openFolder(null)}>
          Racine
        </button>
        {folderId ? (
          <>
            <ChevronRight className="h-3 w-3" />
            <span className="text-foreground">Dossier #{folderId}</span>
          </>
        ) : null}
      </div>

      {showFolderForm ? (
        <form
          className="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-border bg-background p-4"
          onSubmit={(e) => {
            e.preventDefault()
            createFolder.mutate()
          }}
        >
          <div className="min-w-[200px] flex-1">
            <Label htmlFor="folder-name">Nom du dossier</Label>
            <Input
              id="folder-name"
              value={folderName}
              onChange={(e) => setFolderName(e.target.value)}
              required
            />
          </div>
          <Button type="submit" disabled={createFolder.isPending}>
            Créer
          </Button>
        </form>
      ) : null}

      {showDocForm ? (
        <form
          className="mb-4 space-y-3 rounded-xl border border-border bg-background p-4"
          onSubmit={(e) => {
            e.preventDefault()
            createDocument.mutate()
          }}
        >
          <div>
            <Label htmlFor="doc-title">Titre</Label>
            <Input
              id="doc-title"
              value={docTitle}
              onChange={(e) => setDocTitle(e.target.value)}
              required
            />
          </div>
          <div>
            <Label htmlFor="doc-file">Fichier</Label>
            <Input
              id="doc-file"
              type="file"
              required
              onChange={(e) => setDocFile(e.target.files?.[0] ?? null)}
            />
          </div>
          <Button type="submit" disabled={createDocument.isPending || !docFile}>
            <Upload className="h-4 w-4" />
            Uploader
          </Button>
        </form>
      ) : null}

      <div className="grid gap-6 lg:grid-cols-[240px_1fr]">
        <section className="rounded-xl border border-border bg-background p-3">
          <h2 className="mb-2 px-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
            Dossiers
          </h2>
          {folders.length === 0 ? (
            <p className="px-2 py-4 text-xs text-muted-foreground">Aucun sous-dossier.</p>
          ) : (
            <ul className="space-y-0.5">
              {folders.map((folder) => (
                <li key={folder.id}>
                  <button
                    type="button"
                    onClick={() => openFolder(folder.id)}
                    className="flex w-full items-center justify-between rounded-lg px-2 py-2 text-left text-sm hover:bg-muted"
                  >
                    <span className="truncate">{folder.name}</span>
                    <span className="text-[11px] text-muted-foreground">
                      {folder.documents_count ?? 0}
                    </span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </section>

        <section className="rounded-xl border border-border bg-background">
          <div className="border-b border-border px-4 py-3">
            <h2 className="text-sm font-semibold">Documents</h2>
          </div>
          {!folderId ? (
            <div className="p-4">
              <EmptyState
                title="Sélectionnez un dossier"
                description="Choisissez un dossier à gauche pour lister et ajouter des documents."
              />
            </div>
          ) : documents.length === 0 ? (
            <div className="p-4">
              <EmptyState title="Dossier vide" description="Aucun document dans ce dossier." />
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead className="border-b border-border bg-muted/50 text-xs text-muted-foreground">
                  <tr>
                    <th className="px-4 py-2 font-medium">Titre</th>
                    <th className="px-4 py-2 font-medium">Réf.</th>
                    <th className="px-4 py-2 font-medium">Statut</th>
                    <th className="px-4 py-2 font-medium">Date</th>
                  </tr>
                </thead>
                <tbody>
                  {documents.map((doc) => (
                    <tr key={doc.id} className="border-b border-border last:border-0 hover:bg-muted/40">
                      <td className="px-4 py-3">
                        <Link to={`/documents/${doc.id}`} className="font-medium hover:text-primary">
                          {doc.title}
                        </Link>
                      </td>
                      <td className="px-4 py-3 text-xs text-muted-foreground">{doc.reference}</td>
                      <td className="px-4 py-3">
                        <Badge tone={statusTone(doc.status)}>{statusLabel(doc.status)}</Badge>
                      </td>
                      <td className="px-4 py-3 text-xs text-muted-foreground">
                        {formatDate(doc.created_at)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>
      </div>
    </>
  )
}
