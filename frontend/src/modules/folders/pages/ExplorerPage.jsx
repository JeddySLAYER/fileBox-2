import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import {
  Archive,
  ArchiveRestore,
  ChevronRight,
  FilePlus2,
  FolderInput,
  FolderPlus,
  LayoutGrid,
  List,
  MoreVertical,
  Pencil,
  Star,
  Trash2,
  Type,
  Upload,
} from 'lucide-react'
import { toast } from 'sonner'
import { useConfirm } from '@/components/ConfirmDialog'
import Badge from '@/components/ui/Badge'
import Button from '@/components/ui/Button'
import EmptyState from '@/components/ui/EmptyState'
import Input from '@/components/ui/Input'
import Label from '@/components/ui/Label'
import LoadingScreen from '@/components/ui/LoadingScreen'
import Modal from '@/components/ui/Modal'
import PageHeader from '@/components/ui/PageHeader'
import { getErrorMessage } from '@/lib/api'
import { unwrapList, unwrapPaginated } from '@/lib/apiHelpers'
import { fileVisual, folderVisual } from '@/lib/fileIcons'
import { formatDate, statusLabel } from '@/lib/format'
import { can } from '@/lib/permissions'
import { queryKeys } from '@/lib/queryClient'
import { cn } from '@/lib/cn'
import { documentsApi } from '@/modules/documents/api'
import { favoritesApi } from '@/modules/favorites/api'
import { foldersApi } from '@/modules/folders/api'
import { tagsApi } from '@/modules/tags/api'
import { useAuthStore } from '@/stores/authStore'

const VIEW_KEY = 'filebox.explorer.view'

function statusTone(status) {
  if (status === 'valide' || status === 'publie') return 'success'
  if (status === 'en_validation') return 'warning'
  if (status === 'rejete') return 'danger'
  return 'neutral'
}

function ItemIcon({ visual, size = 'md' }) {
  const Icon = visual.Icon
  const box = size === 'lg' ? 'h-12 w-12' : 'h-8 w-8'
  const icon = size === 'lg' ? 'h-7 w-7' : 'h-4 w-4'
  return (
    <span
      className={cn('inline-flex shrink-0 items-center justify-center rounded-lg', box)}
      style={{ backgroundColor: `${visual.color}22`, color: visual.color }}
    >
      <Icon className={icon} />
    </span>
  )
}

export default function ExplorerPage() {
  const user = useAuthStore((s) => s.user)
  const navigate = useNavigate()
  const confirm = useConfirm()
  const queryClient = useQueryClient()
  const [params, setParams] = useSearchParams()
  const folderId = params.get('folder') ? Number(params.get('folder')) : null

  const [viewMode, setViewMode] = useState(() => localStorage.getItem(VIEW_KEY) || 'grid')
  const [showFolderForm, setShowFolderForm] = useState(false)
  const [showDocForm, setShowDocForm] = useState(false)
  const [folderName, setFolderName] = useState('')
  const [folderTagIds, setFolderTagIds] = useState([])
  const [docTitle, setDocTitle] = useState('')
  const [docDescription, setDocDescription] = useState('')
  const [docFile, setDocFile] = useState(null)
  const [docTagIds, setDocTagIds] = useState([])

  const [renameTarget, setRenameTarget] = useState(null) // { type, id, name }
  const [renameValue, setRenameValue] = useState('')
  const [moveTarget, setMoveTarget] = useState(null) // { type, id, name }
  const [moveParentId, setMoveParentId] = useState('')
  const [menu, setMenu] = useState(null) // { item, x, y }

  const folderFilters = useMemo(() => {
    if (folderId == null) return {}
    return { parent_id: folderId }
  }, [folderId])

  const docFilters = useMemo(() => {
    if (folderId == null) return null
    return { folder_id: folderId, per_page: 100 }
  }, [folderId])

  const foldersQuery = useQuery({
    queryKey: queryKeys.folders(folderFilters),
    queryFn: () => foldersApi.list(folderFilters),
  })

  const documentsQuery = useQuery({
    queryKey: queryKeys.documents(docFilters || {}),
    queryFn: () => documentsApi.list(docFilters),
    enabled: Boolean(docFilters),
  })

  const allFoldersQuery = useQuery({
    queryKey: ['folders', 'tree-flat'],
    queryFn: () => foldersApi.tree(),
    enabled: Boolean(moveTarget),
  })

  const treeQuery = useQuery({
    queryKey: queryKeys.folders({ tree: true }),
    queryFn: () => foldersApi.tree(),
  })

  const currentFolderQuery = useQuery({
    queryKey: ['folders', 'detail', folderId],
    queryFn: () => foldersApi.get(folderId),
    enabled: folderId != null,
  })

  const favoritesQuery = useQuery({
    queryKey: queryKeys.favorites,
    queryFn: () => favoritesApi.list(),
  })

  const canPickTags = can(user, 'tags.manage')
  const tagsQuery = useQuery({
    queryKey: queryKeys.tags,
    queryFn: tagsApi.list,
    enabled: canPickTags && (showFolderForm || showDocForm),
  })
  const availableTags = unwrapList(tagsQuery.data)

  const favoritedFolderIds = useMemo(() => {
    const list = unwrapList(favoritesQuery.data)
    return new Set(list.filter((f) => f.folder?.id).map((f) => f.folder.id))
  }, [favoritesQuery.data])

  const favoritedDocIds = useMemo(() => {
    const list = unwrapList(favoritesQuery.data)
    return new Set(list.filter((f) => f.document?.id).map((f) => f.document.id))
  }, [favoritesQuery.data])

  function invalidateExplorer() {
    queryClient.invalidateQueries({ queryKey: ['folders'] })
    queryClient.invalidateQueries({ queryKey: ['documents'] })
    queryClient.invalidateQueries({ queryKey: queryKeys.favorites })
    queryClient.invalidateQueries({ queryKey: queryKeys.dashboard })
  }

  const toggleFolderFavorite = useMutation({
    mutationFn: (id) =>
      favoritedFolderIds.has(id) || currentFolderQuery.data?.is_favorited
        ? favoritesApi.removeFolder(id)
        : favoritesApi.addFolder(id),
    onSuccess: (res) => {
      toast.success(res.message)
      invalidateExplorer()
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const toggleDocFavorite = useMutation({
    mutationFn: (id) =>
      favoritedDocIds.has(id) ? favoritesApi.removeDocument(id) : favoritesApi.addDocument(id),
    onSuccess: (res) => {
      toast.success(res.message)
      invalidateExplorer()
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const createFolder = useMutation({
    mutationFn: () =>
      foldersApi.create({
        name: folderName,
        parent_id: folderId,
        ...(folderTagIds.length ? { tag_ids: folderTagIds.map(Number) } : {}),
      }),
    onSuccess: () => {
      toast.success('Dossier créé')
      setFolderName('')
      setFolderTagIds([])
      setShowFolderForm(false)
      invalidateExplorer()
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  const createDocument = useMutation({
    mutationFn: () => {
      if (!folderId) throw new Error('Sélectionnez un dossier avant d’uploader.')
      const form = new FormData()
      form.append('title', docTitle)
      if (docDescription.trim()) form.append('description', docDescription.trim())
      form.append('folder_id', String(folderId))
      form.append('file', docFile)
      docTagIds.forEach((id) => form.append('tag_ids[]', String(id)))
      return documentsApi.create(form)
    },
    onSuccess: () => {
      toast.success('Document créé')
      setDocTitle('')
      setDocDescription('')
      setDocFile(null)
      setDocTagIds([])
      setShowDocForm(false)
      invalidateExplorer()
    },
    onError: (error) => toast.error(getErrorMessage(error, error.message)),
  })

  const renameMutation = useMutation({
    mutationFn: () => {
      if (renameTarget.type === 'folder') {
        return foldersApi.update(renameTarget.id, { name: renameValue })
      }
      return documentsApi.update(renameTarget.id, { title: renameValue })
    },
    onSuccess: (res) => {
      toast.success(res.message ?? 'Renommé.')
      setRenameTarget(null)
      invalidateExplorer()
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const moveMutation = useMutation({
    mutationFn: () => {
      if (moveTarget.type === 'folder') {
        const parent = moveParentId === '' || moveParentId === 'root' ? null : Number(moveParentId)
        return foldersApi.move(moveTarget.id, parent)
      }
      return documentsApi.move(moveTarget.id, Number(moveParentId))
    },
    onSuccess: (res) => {
      toast.success(res.message ?? 'Déplacé.')
      setMoveTarget(null)
      invalidateExplorer()
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const deleteMutation = useMutation({
    mutationFn: ({ type, id }) =>
      type === 'folder' ? foldersApi.remove(id) : documentsApi.remove(id),
    onSuccess: (res) => {
      toast.success(res.message ?? 'Placé dans la corbeille.')
      invalidateExplorer()
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const archiveMutation = useMutation({
    mutationFn: ({ id, archived }) =>
      archived ? documentsApi.unarchive(id) : documentsApi.archive(id),
    onSuccess: (res) => {
      toast.success(res.message ?? 'Statut d’archivage mis à jour.')
      invalidateExplorer()
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const folders = unwrapList(foldersQuery.data)
  const documents = docFilters ? unwrapPaginated(documentsQuery.data).data : []
  const currentFolder = currentFolderQuery.data
  const moveDestinations = useMemo(
    () => flattenFolders(unwrapList(allFoldersQuery.data)),
    [allFoldersQuery.data],
  )
  const treeRoots = unwrapList(treeQuery.data)

  const currentIsFavorite =
    folderId != null &&
    (currentFolder?.is_favorited || favoritedFolderIds.has(folderId))

  const items = useMemo(() => {
    const folderItems = folders.map((f) => ({
      key: `folder-${f.id}`,
      type: 'folder',
      id: f.id,
      name: f.name,
      isFavorite: Boolean(f.is_favorited || favoritedFolderIds.has(f.id)),
      meta: `${f.documents_count ?? 0} doc · ${f.children_count ?? 0} sous-dossiers`,
      date: f.updated_at || f.created_at,
      raw: f,
      visual: folderVisual(),
    }))
    const docItems = documents.map((d) => {
      const visual = fileVisual(d)
      return {
        key: `doc-${d.id}`,
        type: 'document',
        id: d.id,
        name: d.title,
        isFavorite: Boolean(d.is_favorited || favoritedDocIds.has(d.id)),
        meta: [visual.ext?.toUpperCase(), statusLabel(d.status)].filter(Boolean).join(' · '),
        status: d.status,
        date: d.updated_at || d.created_at,
        isEditable: Boolean(d.is_editable),
        raw: d,
        visual,
      }
    })
    return [...folderItems, ...docItems]
  }, [folders, documents, favoritedFolderIds, favoritedDocIds])

  function openFolder(id) {
    const next = new URLSearchParams(params)
    if (id == null) next.delete('folder')
    else next.set('folder', String(id))
    setParams(next)
  }

  function setView(mode) {
    setViewMode(mode)
    localStorage.setItem(VIEW_KEY, mode)
  }

  function openRename(item) {
    setRenameTarget({ type: item.type, id: item.id, name: item.name })
    setRenameValue(item.name)
  }

  function openMove(item) {
    setMoveTarget({ type: item.type, id: item.id, name: item.name })
    setMoveParentId(item.type === 'document' ? String(folderId ?? '') : folderId ? String(folderId) : 'root')
  }

  async function askDelete(item) {
    const ok = await confirm({
      title: item.type === 'folder' ? 'Supprimer le dossier' : 'Supprimer le document',
      description:
        item.type === 'folder'
          ? `« ${item.name} » et tout son contenu seront placés dans la corbeille.`
          : `« ${item.name} » sera placé dans la corbeille.`,
      confirmLabel: 'Supprimer',
    })
    if (ok) deleteMutation.mutate({ type: item.type, id: item.id })
  }

  function onOpen(item) {
    if (item.type === 'folder') openFolder(item.id)
    else navigate(`/documents/${item.id}`)
  }

  const canUpdateFolder = can(user, 'folders.update')
  const canDeleteFolder = can(user, 'folders.delete')
  const canUpdateDoc = can(user, 'documents.update')
  const canDeleteDoc = can(user, 'documents.delete')
  const canArchiveDoc = can(user, 'documents.archive')

  function openMenu(item, event) {
    event.preventDefault()
    event.stopPropagation()
    setMenu({ item, x: event.clientX, y: event.clientY })
  }

  function openMenuFromButton(item, event) {
    event.preventDefault()
    event.stopPropagation()
    const rect = event.currentTarget.getBoundingClientRect()
    setMenu({ item, x: rect.right, y: rect.bottom + 4 })
  }

  function closeMenu() {
    setMenu(null)
  }

  useEffect(() => {
    if (!menu) return undefined
    const onKey = (e) => {
      if (e.key === 'Escape') closeMenu()
    }
    const onClick = () => closeMenu()
    window.addEventListener('keydown', onKey)
    window.addEventListener('click', onClick)
    window.addEventListener('scroll', closeMenu, true)
    return () => {
      window.removeEventListener('keydown', onKey)
      window.removeEventListener('click', onClick)
      window.removeEventListener('scroll', closeMenu, true)
    }
  }, [menu])

  function runMenuAction(fn) {
    closeMenu()
    fn?.()
  }

  if (foldersQuery.isLoading || (docFilters && documentsQuery.isLoading)) {
    return <LoadingScreen />
  }

  return (
    <>
      <PageHeader
        title="Explorateur"
        description="Parcourez, organisez et gérez dossiers et documents."
        actions={
          <>
            <div className="flex rounded-lg border border-border p-0.5">
              <Button
                size="sm"
                variant={viewMode === 'grid' ? 'secondary' : 'ghost'}
                title="Vue grille"
                onClick={() => setView('grid')}
              >
                <LayoutGrid className="h-4 w-4" />
              </Button>
              <Button
                size="sm"
                variant={viewMode === 'list' ? 'secondary' : 'ghost'}
                title="Vue liste"
                onClick={() => setView('list')}
              >
                <List className="h-4 w-4" />
              </Button>
            </div>
            {folderId ? (
              <Button
                size="sm"
                variant="secondary"
                disabled={toggleFolderFavorite.isPending}
                onClick={() => toggleFolderFavorite.mutate(folderId)}
              >
                <Star
                  className={`h-4 w-4 ${currentIsFavorite ? 'fill-current text-amber-500' : ''}`}
                />
                {currentIsFavorite ? 'Retirer' : 'Favori'}
              </Button>
            ) : null}
            <Button as={Link} to="/trash" variant="ghost" size="sm">
              <Trash2 className="h-4 w-4" />
              Corbeille
            </Button>
            {can(user, 'folders.create') ? (
              <Button
                variant="secondary"
                size="sm"
                onClick={() => {
                  setFolderName('')
                  setFolderTagIds([])
                  setShowFolderForm(true)
                }}
              >
                <FolderPlus className="h-4 w-4" />
                Nouveau dossier
              </Button>
            ) : null}
            {can(user, 'documents.create') ? (
              <Button
                size="sm"
                onClick={() => {
                  setDocTitle('')
                  setDocDescription('')
                  setDocFile(null)
                  setDocTagIds([])
                  setShowDocForm(true)
                }}
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
            <span className="text-foreground">{currentFolder?.name ?? `Dossier #${folderId}`}</span>
          </>
        ) : null}
      </div>

      <Modal
        open={showFolderForm}
        onClose={() => {
          setShowFolderForm(false)
          setFolderName('')
          setFolderTagIds([])
        }}
        title="Nouveau dossier"
        description={
          folderId
            ? `Créé dans « ${currentFolder?.name ?? 'ce dossier'} ».`
            : 'Créé à la racine de l’explorateur.'
        }
        footer={
          <>
            <Button
              type="button"
              variant="secondary"
              onClick={() => {
                setShowFolderForm(false)
                setFolderName('')
                setFolderTagIds([])
              }}
            >
              Annuler
            </Button>
            <Button
              type="submit"
              form="create-folder-form"
              disabled={createFolder.isPending || !folderName.trim()}
            >
              Créer
            </Button>
          </>
        }
      >
        <form
          id="create-folder-form"
          className="space-y-3"
          onSubmit={(e) => {
            e.preventDefault()
            createFolder.mutate()
          }}
        >
          <div>
            <Label htmlFor="folder-name">Nom du dossier</Label>
            <Input
              id="folder-name"
              className="mt-1"
              value={folderName}
              onChange={(e) => setFolderName(e.target.value)}
              required
              autoFocus
            />
          </div>
          {canPickTags ? (
            <div>
              <Label>Tags (facultatif)</Label>
              <div className="mt-2 flex max-h-40 flex-wrap gap-2 overflow-y-auto">
                {availableTags.map((tag) => (
                  <label
                    key={tag.id}
                    className="flex cursor-pointer items-center gap-1 rounded border border-border px-2 py-1 text-xs"
                  >
                    <input
                      type="checkbox"
                      checked={folderTagIds.includes(tag.id)}
                      onChange={() =>
                        setFolderTagIds((prev) =>
                          prev.includes(tag.id)
                            ? prev.filter((x) => x !== tag.id)
                            : [...prev, tag.id],
                        )
                      }
                    />
                    {tag.name}
                  </label>
                ))}
                {!availableTags.length ? (
                  <p className="text-xs text-muted-foreground">Aucun tag disponible.</p>
                ) : null}
              </div>
            </div>
          ) : null}
        </form>
      </Modal>

      <Modal
        open={showDocForm}
        onClose={() => {
          setShowDocForm(false)
          setDocTitle('')
          setDocDescription('')
          setDocFile(null)
          setDocTagIds([])
        }}
        title="Nouveau document"
        description={`Ajout dans « ${currentFolder?.name ?? 'le dossier courant'} ».`}
        size="lg"
        footer={
          <>
            <Button
              type="button"
              variant="secondary"
              onClick={() => {
                setShowDocForm(false)
                setDocTitle('')
                setDocDescription('')
                setDocFile(null)
                setDocTagIds([])
              }}
            >
              Annuler
            </Button>
            <Button
              type="submit"
              form="create-doc-form"
              disabled={createDocument.isPending || !docFile || !docTitle.trim()}
            >
              <Upload className="h-4 w-4" />
              Uploader
            </Button>
          </>
        }
      >
        <form
          id="create-doc-form"
          className="space-y-3"
          onSubmit={(e) => {
            e.preventDefault()
            createDocument.mutate()
          }}
        >
          <div>
            <Label htmlFor="doc-title">Titre</Label>
            <Input
              id="doc-title"
              className="mt-1"
              value={docTitle}
              onChange={(e) => setDocTitle(e.target.value)}
              required
              autoFocus
            />
          </div>
          <div>
            <Label htmlFor="doc-file">Fichier</Label>
            <Input
              id="doc-file"
              className="mt-1"
              type="file"
              required
              onChange={(e) => setDocFile(e.target.files?.[0] ?? null)}
            />
          </div>
          <div>
            <Label htmlFor="doc-desc">Description (facultatif)</Label>
            <Input
              id="doc-desc"
              className="mt-1"
              value={docDescription}
              onChange={(e) => setDocDescription(e.target.value)}
              placeholder="Résumé ou contexte du fichier…"
            />
          </div>
          {canPickTags ? (
            <div>
              <Label>Tags (facultatif)</Label>
              <div className="mt-2 flex max-h-40 flex-wrap gap-2 overflow-y-auto">
                {availableTags.map((tag) => (
                  <label
                    key={tag.id}
                    className="flex cursor-pointer items-center gap-1 rounded border border-border px-2 py-1 text-xs"
                  >
                    <input
                      type="checkbox"
                      checked={docTagIds.includes(tag.id)}
                      onChange={() =>
                        setDocTagIds((prev) =>
                          prev.includes(tag.id)
                            ? prev.filter((x) => x !== tag.id)
                            : [...prev, tag.id],
                        )
                      }
                    />
                    {tag.name}
                  </label>
                ))}
                {!availableTags.length ? (
                  <p className="text-xs text-muted-foreground">Aucun tag disponible.</p>
                ) : null}
              </div>
            </div>
          ) : null}
        </form>
      </Modal>

      <div className="grid gap-6 lg:grid-cols-[220px_1fr]">
        <aside className="rounded-xl border border-border bg-background p-3">
          <h2 className="mb-2 px-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
            Arborescence
          </h2>
          <button
            type="button"
            onClick={() => openFolder(null)}
            className={cn(
              'mb-1 w-full rounded-lg px-2 py-1.5 text-left text-sm hover:bg-muted',
              folderId == null && 'bg-muted font-medium',
            )}
          >
            Racine
          </button>
          <TreeNav
            nodes={treeRoots}
            activeId={folderId}
            onOpen={openFolder}
            depth={0}
          />
        </aside>

        <section className="rounded-xl border border-border bg-background">
          <div className="flex items-center justify-between border-b border-border px-4 py-3">
            <h2 className="text-sm font-semibold">
              {folderId ? currentFolder?.name ?? 'Dossier' : 'Racine'}
            </h2>
            <span className="text-xs text-muted-foreground">{items.length} élément(s)</span>
          </div>

          {!items.length ? (
            <div className="p-4">
              <EmptyState
                title={folderId ? 'Dossier vide' : 'Aucun dossier'}
                description={
                  folderId
                    ? 'Ajoutez un sous-dossier ou un document.'
                    : 'Créez un dossier pour commencer.'
                }
              />
            </div>
          ) : viewMode === 'grid' ? (
            <div className="grid grid-cols-2 gap-3 p-4 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-5">
              {items.map((item) => (
                <div
                  key={item.key}
                  className="group relative flex flex-col items-stretch rounded-xl border border-border p-3 hover:bg-muted/40"
                  onContextMenu={(e) => openMenu(item, e)}
                >
                  <button
                    type="button"
                    title="Actions"
                    className="absolute right-1.5 top-1.5 z-10 rounded-md p-1 text-muted-foreground opacity-70 hover:bg-muted hover:text-foreground group-hover:opacity-100"
                    onClick={(e) => openMenuFromButton(item, e)}
                  >
                    <MoreVertical className="h-4 w-4" />
                  </button>
                  <button
                    type="button"
                    className="flex flex-1 flex-col items-center gap-2 text-center"
                    onDoubleClick={() => onOpen(item)}
                    onClick={() => onOpen(item)}
                  >
                    <ItemIcon visual={item.visual} size="lg" />
                    <span className="line-clamp-2 w-full text-sm font-medium">{item.name}</span>
                    <span className="line-clamp-1 text-[11px] text-muted-foreground">{item.meta}</span>
                  </button>
                </div>
              ))}
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead className="border-b border-border bg-muted/50 text-xs text-muted-foreground">
                  <tr>
                    <th className="px-4 py-2 font-medium">Nom</th>
                    <th className="px-4 py-2 font-medium">Type</th>
                    <th className="px-4 py-2 font-medium">Infos</th>
                    <th className="px-4 py-2 font-medium">Modifié</th>
                    <th className="w-[1%] px-4 py-2 font-medium" />
                  </tr>
                </thead>
                <tbody>
                  {items.map((item) => (
                    <tr
                      key={item.key}
                      className="border-b border-border last:border-0 hover:bg-muted/40"
                      onContextMenu={(e) => openMenu(item, e)}
                    >
                      <td className="px-4 py-2.5">
                        <button
                          type="button"
                          className="flex items-center gap-2 text-left font-medium hover:text-primary"
                          onClick={() => onOpen(item)}
                        >
                          <ItemIcon visual={item.visual} />
                          <span className="truncate">{item.name}</span>
                          {item.isFavorite ? (
                            <Star className="h-3.5 w-3.5 fill-current text-amber-500" />
                          ) : null}
                        </button>
                      </td>
                      <td className="px-4 py-2.5 text-xs text-muted-foreground">
                        {item.visual.label}
                      </td>
                      <td className="px-4 py-2.5">
                        {item.type === 'document' && item.status ? (
                          <Badge tone={statusTone(item.status)}>{statusLabel(item.status)}</Badge>
                        ) : (
                          <span className="text-xs text-muted-foreground">{item.meta}</span>
                        )}
                      </td>
                      <td className="px-4 py-2.5 text-xs text-muted-foreground">
                        {formatDate(item.date)}
                      </td>
                      <td className="px-4 py-2.5">
                        <button
                          type="button"
                          title="Actions"
                          className="rounded-md p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                          onClick={(e) => openMenuFromButton(item, e)}
                        >
                          <MoreVertical className="h-4 w-4" />
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>
      </div>

      {menu ? (
        <ItemContextMenu
          menu={menu}
          canUpdate={menu.item.type === 'folder' ? canUpdateFolder : canUpdateDoc}
          canDelete={menu.item.type === 'folder' ? canDeleteFolder : canDeleteDoc}
          canArchive={menu.item.type === 'document' && canArchiveDoc}
          onOpen={() => runMenuAction(() => onOpen(menu.item))}
          onFavorite={() =>
            runMenuAction(() =>
              menu.item.type === 'folder'
                ? toggleFolderFavorite.mutate(menu.item.id)
                : toggleDocFavorite.mutate(menu.item.id),
            )
          }
          onRename={() => runMenuAction(() => openRename(menu.item))}
          onMove={() => runMenuAction(() => openMove(menu.item))}
          onEdit={
            menu.item.type === 'document' && menu.item.isEditable && menu.item.status !== 'archive'
              ? () => runMenuAction(() => navigate(`/documents/${menu.item.id}?tab=content`))
              : null
          }
          onArchive={
            menu.item.type === 'document' && canArchiveDoc
              ? () =>
                  runMenuAction(() =>
                    archiveMutation.mutate({
                      id: menu.item.id,
                      archived: menu.item.status === 'archive',
                    }),
                  )
              : null
          }
          onDelete={() => runMenuAction(() => askDelete(menu.item))}
        />
      ) : null}

      <Modal
        open={Boolean(renameTarget)}
        onClose={() => setRenameTarget(null)}
        title="Renommer"
        footer={
          <>
            <Button type="button" variant="secondary" onClick={() => setRenameTarget(null)}>
              Annuler
            </Button>
            <Button
              type="button"
              disabled={!renameValue.trim() || renameMutation.isPending}
              onClick={() => renameMutation.mutate()}
            >
              Enregistrer
            </Button>
          </>
        }
      >
        <Label htmlFor="rename-value">Nouveau nom</Label>
        <Input
          id="rename-value"
          value={renameValue}
          onChange={(e) => setRenameValue(e.target.value)}
          autoFocus
        />
      </Modal>

      <Modal
        open={Boolean(moveTarget)}
        onClose={() => setMoveTarget(null)}
        title={`Déplacer — ${moveTarget?.name ?? ''}`}
        description={
          moveTarget?.type === 'document'
            ? 'Choisissez le dossier de destination.'
            : 'Choisissez le dossier parent (ou la racine).'
        }
        footer={
          <>
            <Button type="button" variant="secondary" onClick={() => setMoveTarget(null)}>
              Annuler
            </Button>
            <Button
              type="button"
              disabled={
                moveMutation.isPending ||
                (moveTarget?.type === 'document' && !moveParentId) ||
                (moveTarget?.type === 'folder' && moveParentId === String(moveTarget.id))
              }
              onClick={() => moveMutation.mutate()}
            >
              Déplacer
            </Button>
          </>
        }
      >
        <Label htmlFor="move-dest">Destination</Label>
        <select
          id="move-dest"
          className="mt-1 h-11 w-full rounded-lg border border-border bg-background px-3 text-sm"
          value={moveParentId}
          onChange={(e) => setMoveParentId(e.target.value)}
        >
          {moveTarget?.type === 'folder' ? <option value="root">— Racine —</option> : null}
          {moveDestinations
            .filter((f) => !(moveTarget?.type === 'folder' && f.id === moveTarget.id))
            .map((f) => (
              <option key={f.id} value={f.id}>
                {f.name}
              </option>
            ))}
        </select>
        {moveTarget?.type === 'document' && !moveDestinations.length ? (
          <p className="mt-2 text-xs text-muted-foreground">Aucun dossier disponible.</p>
        ) : null}
      </Modal>
    </>
  )
}

function flattenFolders(nodes, prefix = '') {
  const out = []
  for (const n of nodes || []) {
    const label = prefix ? `${prefix} / ${n.name}` : n.name
    out.push({ id: n.id, name: label })
    if (n.children?.length) out.push(...flattenFolders(n.children, label))
  }
  return out
}

function TreeNav({ nodes, activeId, onOpen, depth }) {
  if (!nodes?.length) return null
  return (
    <ul className="space-y-0.5">
      {nodes.map((node) => (
        <li key={node.id}>
          <button
            type="button"
            onClick={() => onOpen(node.id)}
            style={{ paddingLeft: 8 + depth * 12 }}
            className={cn(
              'flex w-full items-center gap-1.5 rounded-lg py-1.5 pr-2 text-left text-sm hover:bg-muted',
              activeId === node.id && 'bg-muted font-medium',
            )}
          >
            <span
              className="inline-block h-2.5 w-2.5 shrink-0 rounded-sm"
              style={{ backgroundColor: '#F4B400' }}
            />
            <span className="truncate">{node.name}</span>
          </button>
          {node.children?.length ? (
            <TreeNav nodes={node.children} activeId={activeId} onOpen={onOpen} depth={depth + 1} />
          ) : null}
        </li>
      ))}
    </ul>
  )
}

function ItemContextMenu({
  menu,
  canUpdate,
  canDelete,
  canArchive,
  onOpen,
  onFavorite,
  onRename,
  onMove,
  onEdit,
  onArchive,
  onDelete,
}) {
  const { item, x, y } = menu
  const left = Math.min(x, window.innerWidth - 220)
  const top = Math.min(y, window.innerHeight - 320)
  const isArchived = item.type === 'document' && item.status === 'archive'

  const entries = [
    { key: 'open', label: 'Ouvrir', icon: ChevronRight, onClick: onOpen },
    {
      key: 'favorite',
      label: item.isFavorite ? 'Retirer des favoris' : 'Ajouter aux favoris',
      icon: Star,
      onClick: onFavorite,
    },
    onEdit ? { key: 'edit', label: 'Éditer', icon: Pencil, onClick: onEdit } : null,
    canUpdate ? { key: 'rename', label: 'Renommer', icon: Type, onClick: onRename } : null,
    canUpdate ? { key: 'move', label: 'Déplacer', icon: FolderInput, onClick: onMove } : null,
    canArchive && onArchive
      ? {
          key: 'archive',
          label: isArchived ? 'Désarchiver' : 'Archiver',
          icon: isArchived ? ArchiveRestore : Archive,
          onClick: onArchive,
        }
      : null,
    canDelete
      ? { key: 'delete', label: 'Supprimer', icon: Trash2, onClick: onDelete, danger: true }
      : null,
  ].filter(Boolean)

  return (
    <div
      role="menu"
      className="fixed z-[120] min-w-[200px] overflow-hidden rounded-xl border border-border bg-background py-1 shadow-soft"
      style={{ left, top }}
      onClick={(e) => e.stopPropagation()}
      onContextMenu={(e) => e.preventDefault()}
    >
      <p className="truncate border-b border-border px-3 py-2 text-xs text-muted-foreground">
        {item.name}
      </p>
      {entries.map((entry) => {
        const Icon = entry.icon
        return (
          <button
            key={entry.key}
            type="button"
            role="menuitem"
            className={cn(
              'flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-muted',
              entry.danger && 'text-red-600 hover:bg-red-50',
            )}
            onClick={entry.onClick}
          >
            <Icon
              className={cn(
                'h-4 w-4',
                entry.key === 'favorite' && item.isFavorite && 'fill-current text-amber-500',
              )}
            />
            {entry.label}
          </button>
        )
      })}
    </div>
  )
}
