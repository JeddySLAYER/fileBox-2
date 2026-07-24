import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import {
  Archive,
  ArrowLeft,
  Download,
  Eye,
  Save,
  Trash2,
  Upload,
} from 'lucide-react'
import { toast } from 'sonner'
import Tabs from '@/components/ui/Tabs'
import Badge from '@/components/ui/Badge'
import Button from '@/components/ui/Button'
import Card from '@/components/ui/Card'
import EmptyState from '@/components/ui/EmptyState'
import Input from '@/components/ui/Input'
import Label from '@/components/ui/Label'
import LoadingScreen from '@/components/ui/LoadingScreen'
import PageHeader from '@/components/ui/PageHeader'
import DocumentAccessPanel from '@/modules/access/components/DocumentAccessPanel'
import DocumentCommentsPanel from '@/modules/comments/components/DocumentCommentsPanel'
import DocumentValidationsPanel from '@/modules/validations/components/DocumentValidationsPanel'
import api, { getErrorMessage } from '@/lib/api'
import { unwrapList } from '@/lib/apiHelpers'
import { formatBytes, formatDate, statusLabel } from '@/lib/format'
import { can, canAny } from '@/lib/permissions'
import { queryKeys } from '@/lib/queryClient'
import { downloadDocument, documentsApi } from '@/modules/documents/api'
import { documentTypesApi } from '@/modules/document-types/api'
import { tagsApi } from '@/modules/tags/api'
import { useAuthStore } from '@/stores/authStore'

function statusTone(status) {
  if (status === 'valide' || status === 'publie') return 'success'
  if (status === 'en_validation') return 'warning'
  if (status === 'rejete') return 'danger'
  return 'neutral'
}

export default function DocumentDetailPage() {
  const { id } = useParams()
  const user = useAuthStore((s) => s.user)
  const queryClient = useQueryClient()
  const [tab, setTab] = useState('overview')
  const [meta, setMeta] = useState({ title: '', description: '', document_type_id: '', is_editable: false })
  const [tagIds, setTagIds] = useState([])
  const [versionFile, setVersionFile] = useState(null)
  const [content, setContent] = useState('')

  const { data: document, isLoading, isError } = useQuery({
    queryKey: queryKeys.document(id),
    queryFn: () => documentsApi.get(id),
    enabled: Boolean(id),
  })

  const versionsQuery = useQuery({
    queryKey: queryKeys.documentVersions(id),
    queryFn: () => documentsApi.versions(id),
    enabled: Boolean(id) && tab === 'versions',
  })

  const typesQuery = useQuery({
    queryKey: queryKeys.documentTypes({}),
    queryFn: () => documentTypesApi.list(),
    enabled: can(user, 'documents.update'),
  })

  const tagsQuery = useQuery({
    queryKey: queryKeys.tags,
    queryFn: tagsApi.list,
    enabled: can(user, 'tags.manage') || can(user, 'documents.update'),
  })

  useEffect(() => {
    if (!document) return
    setMeta({
      title: document.title ?? '',
      description: document.description ?? '',
      document_type_id: document.document_type?.id ?? '',
      is_editable: Boolean(document.is_editable),
    })
    setTagIds((document.tags ?? []).map((t) => t.id))
  }, [document])

  useEffect(() => {
    if (tab !== 'content' || !document?.is_editable) return
    documentsApi
      .content(id)
      .then((data) => setContent(data.content ?? ''))
      .catch((e) => toast.error(getErrorMessage(e)))
  }, [tab, id, document?.is_editable])

  const invalidateDoc = () => {
    queryClient.invalidateQueries({ queryKey: queryKeys.document(id) })
    queryClient.invalidateQueries({ queryKey: queryKeys.documentVersions(id) })
  }

  const saveMeta = useMutation({
    mutationFn: () =>
      documentsApi.update(id, {
        title: meta.title,
        description: meta.description || null,
        document_type_id: meta.document_type_id ? Number(meta.document_type_id) : null,
        is_editable: meta.is_editable,
        tag_ids: tagIds,
      }),
    onSuccess: (res) => {
      toast.success(res.message)
      invalidateDoc()
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const uploadVersion = useMutation({
    mutationFn: () => {
      const form = new FormData()
      form.append('file', versionFile)
      return documentsApi.storeVersion(id, form)
    },
    onSuccess: (res) => {
      toast.success(res.message)
      setVersionFile(null)
      invalidateDoc()
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const saveContent = useMutation({
    mutationFn: () => documentsApi.saveContent(id, { content }),
    onSuccess: (res) => {
      toast.success(res.message)
      invalidateDoc()
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const archive = useMutation({
    mutationFn: () =>
      document.status === 'archive'
        ? documentsApi.unarchive(id)
        : documentsApi.archive(id),
    onSuccess: (res) => {
      toast.success(res.message)
      invalidateDoc()
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const removeDoc = useMutation({
    mutationFn: () => documentsApi.remove(id),
    onSuccess: (res) => {
      toast.success(res.message)
      window.location.assign('/explorer')
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  async function handleDownload() {
    try {
      await downloadDocument(id, document?.current_version?.file_name ?? 'document')
    } catch (error) {
      toast.error(getErrorMessage(error, 'Téléchargement impossible.'))
    }
  }

  async function handlePreview() {
    try {
      const response = await api.get(`/documents/${id}/preview`, { responseType: 'blob' })
      const url = window.URL.createObjectURL(response.data)
      window.open(url, '_blank', 'noopener,noreferrer')
    } catch (error) {
      toast.error(getErrorMessage(error, 'Prévisualisation impossible.'))
    }
  }

  if (isLoading) return <LoadingScreen />
  if (isError || !document) return <EmptyState title="Document introuvable" />

  const version = document.current_version
  const types = unwrapList(typesQuery.data)
  const tags = unwrapList(tagsQuery.data)
  const versions = unwrapList(versionsQuery.data)

  const tabs = [
    { id: 'overview', label: 'Aperçu' },
    { id: 'versions', label: 'Versions' },
    document.is_editable ? { id: 'content', label: 'Édition' } : null,
    { id: 'comments', label: 'Commentaires' },
    { id: 'validations', label: 'Validations' },
    canAny(user, ['documents.share', 'accesses.manage'])
      ? { id: 'access', label: 'Partage' }
      : null,
  ].filter(Boolean)

  return (
    <>
      <PageHeader
        title={document.title}
        description={`${document.reference} · ${document.folder?.name ?? 'Sans dossier'}`}
        actions={
          <>
            <Button as={Link} to="/explorer" variant="secondary" size="sm">
              <ArrowLeft className="h-4 w-4" />
              Explorateur
            </Button>
            <Button variant="secondary" size="sm" onClick={handlePreview}>
              <Eye className="h-4 w-4" />
              Aperçu
            </Button>
            <Button size="sm" onClick={handleDownload}>
              <Download className="h-4 w-4" />
              Télécharger
            </Button>
            {can(user, 'documents.archive') ? (
              <Button size="sm" variant="secondary" onClick={() => archive.mutate()}>
                <Archive className="h-4 w-4" />
                {document.status === 'archive' ? 'Désarchiver' : 'Archiver'}
              </Button>
            ) : null}
            {can(user, 'documents.delete') ? (
              <Button
                size="sm"
                variant="ghost"
                onClick={() => {
                  if (window.confirm('Mettre ce document en corbeille ?')) removeDoc.mutate()
                }}
              >
                <Trash2 className="h-4 w-4" />
              </Button>
            ) : null}
          </>
        }
      />

      <Tabs tabs={tabs} active={tab} onChange={setTab} />

      <div className="mt-6">
        {tab === 'overview' ? (
          <div className="grid gap-4 lg:grid-cols-[1.2fr_0.8fr]">
            <Card>
              <div className="flex flex-wrap items-center gap-2">
                <Badge tone={statusTone(document.status)}>{statusLabel(document.status)}</Badge>
                {document.is_editable ? <Badge tone="primary">Éditable</Badge> : null}
              </div>

              {can(user, 'documents.update') && document.status !== 'archive' ? (
                <form
                  className="mt-5 space-y-3"
                  onSubmit={(e) => {
                    e.preventDefault()
                    saveMeta.mutate()
                  }}
                >
                  <div>
                    <Label>Titre</Label>
                    <Input
                      value={meta.title}
                      onChange={(e) => setMeta({ ...meta, title: e.target.value })}
                      required
                    />
                  </div>
                  <div>
                    <Label>Description</Label>
                    <Input
                      value={meta.description}
                      onChange={(e) => setMeta({ ...meta, description: e.target.value })}
                    />
                  </div>
                  <div>
                    <Label>Type</Label>
                    <select
                      className="h-11 w-full rounded-lg border border-border bg-background px-3 text-sm"
                      value={meta.document_type_id}
                      onChange={(e) => setMeta({ ...meta, document_type_id: e.target.value })}
                    >
                      <option value="">— Aucun —</option>
                      {types.map((t) => (
                        <option key={t.id} value={t.id}>
                          {t.name}
                        </option>
                      ))}
                    </select>
                  </div>
                  <label className="flex items-center gap-2 text-sm">
                    <input
                      type="checkbox"
                      checked={meta.is_editable}
                      onChange={(e) => setMeta({ ...meta, is_editable: e.target.checked })}
                    />
                    Éditable en ligne
                  </label>
                  {can(user, 'tags.manage') || can(user, 'documents.update') ? (
                    <div>
                      <Label>Tags</Label>
                      <div className="mt-2 flex flex-wrap gap-2">
                        {tags.map((tag) => (
                          <label
                            key={tag.id}
                            className="flex cursor-pointer items-center gap-1 rounded border border-border px-2 py-1 text-xs"
                          >
                            <input
                              type="checkbox"
                              checked={tagIds.includes(tag.id)}
                              onChange={() =>
                                setTagIds((prev) =>
                                  prev.includes(tag.id)
                                    ? prev.filter((x) => x !== tag.id)
                                    : [...prev, tag.id],
                                )
                              }
                            />
                            {tag.name}
                          </label>
                        ))}
                      </div>
                    </div>
                  ) : null}
                  <Button type="submit" size="sm" disabled={saveMeta.isPending}>
                    <Save className="h-4 w-4" />
                    Enregistrer
                  </Button>
                </form>
              ) : (
                <dl className="mt-5 grid gap-4 sm:grid-cols-2">
                  <div>
                    <dt className="text-xs text-muted-foreground">Auteur</dt>
                    <dd className="mt-1 text-sm">{document.author?.name ?? '—'}</dd>
                  </div>
                  <div>
                    <dt className="text-xs text-muted-foreground">Type</dt>
                    <dd className="mt-1 text-sm">{document.document_type?.name ?? '—'}</dd>
                  </div>
                  <div>
                    <dt className="text-xs text-muted-foreground">Confidentialité</dt>
                    <dd className="mt-1 text-sm">{document.confidentiality ?? '—'}</dd>
                  </div>
                  <div>
                    <dt className="text-xs text-muted-foreground">Créé le</dt>
                    <dd className="mt-1 text-sm">{formatDate(document.created_at, true)}</dd>
                  </div>
                </dl>
              )}

              <div className="mt-6 rounded-lg border border-dashed border-border bg-muted/40 p-4">
                <p className="text-xs font-medium">OCR & IA</p>
                <p className="mt-1 text-xs text-muted-foreground">
                  Emplacement réservé pour l&apos;extraction OCR et les suggestions IA.
                </p>
              </div>
            </Card>

            <Card>
              <h2 className="text-sm font-semibold">Version courante</h2>
              {version ? (
                <dl className="mt-4 space-y-3 text-sm">
                  <div className="flex justify-between gap-3">
                    <dt className="text-muted-foreground">Fichier</dt>
                    <dd className="truncate font-medium">{version.file_name}</dd>
                  </div>
                  <div className="flex justify-between gap-3">
                    <dt className="text-muted-foreground">N°</dt>
                    <dd>v{version.version_number}</dd>
                  </div>
                  <div className="flex justify-between gap-3">
                    <dt className="text-muted-foreground">Taille</dt>
                    <dd>{formatBytes(version.size)}</dd>
                  </div>
                </dl>
              ) : (
                <p className="mt-4 text-sm text-muted-foreground">Aucune version.</p>
              )}
              {(document.tags ?? []).length > 0 ? (
                <div className="mt-6 flex flex-wrap gap-1.5">
                  {document.tags.map((tag) => (
                    <Badge key={tag.id}>{tag.name}</Badge>
                  ))}
                </div>
              ) : null}
            </Card>
          </div>
        ) : null}

        {tab === 'versions' ? (
          <div className="space-y-4">
            {canAny(user, ['documents.update', 'versions.manage']) &&
            document.status !== 'archive' ? (
              <Card>
                <Label>Nouvelle version (réupload)</Label>
                <div className="mt-2 flex flex-wrap gap-2">
                  <Input
                    type="file"
                    onChange={(e) => setVersionFile(e.target.files?.[0] ?? null)}
                  />
                  <Button
                    size="sm"
                    disabled={!versionFile || uploadVersion.isPending}
                    onClick={() => uploadVersion.mutate()}
                  >
                    <Upload className="h-4 w-4" />
                    Uploader
                  </Button>
                </div>
              </Card>
            ) : null}

            {versionsQuery.isLoading ? (
              <LoadingScreen />
            ) : versions.length === 0 ? (
              <EmptyState title="Aucune version" />
            ) : (
              <ul className="divide-y divide-border rounded-xl border border-border bg-background">
                {versions.map((v) => (
                  <li key={v.id} className="flex items-center justify-between gap-3 px-4 py-3">
                    <div>
                      <p className="text-sm font-medium">
                        v{v.version_number} — {v.file_name}
                      </p>
                      <p className="text-xs text-muted-foreground">
                        {formatBytes(v.size)} · {formatDate(v.created_at, true)}
                        {v.change_summary ? ` · ${v.change_summary}` : ''}
                      </p>
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </div>
        ) : null}

        {tab === 'content' ? (
          <Card>
            <Label>Contenu éditable</Label>
            <textarea
              className="mt-2 min-h-[280px] w-full rounded-lg border border-border bg-background p-3 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
              value={content}
              onChange={(e) => setContent(e.target.value)}
            />
            <Button
              className="mt-3"
              size="sm"
              onClick={() => saveContent.mutate()}
              disabled={saveContent.isPending}
            >
              <Save className="h-4 w-4" />
              Sauvegarder (nouvelle version)
            </Button>
          </Card>
        ) : null}

        {tab === 'comments' ? <DocumentCommentsPanel documentId={id} /> : null}
        {tab === 'validations' ? (
          <DocumentValidationsPanel
            documentId={id}
            documentStatus={document.status}
            onUpdated={invalidateDoc}
          />
        ) : null}
        {tab === 'access' ? <DocumentAccessPanel documentId={id} /> : null}
      </div>
    </>
  )
}
