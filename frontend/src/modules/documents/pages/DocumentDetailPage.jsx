import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import {
  Archive,
  ArrowLeft,
  Download,
  Eye,
  PenLine,
  Pencil,
  Save,
  ScanText,
  Send,
  Sparkles,
  Star,
  SunMedium,
  Trash2,
  Upload,
} from 'lucide-react'
import { toast } from 'sonner'
import { useConfirm } from '@/components/ConfirmDialog'
import Tabs from '@/components/ui/Tabs'
import Badge from '@/components/ui/Badge'
import Button from '@/components/ui/Button'
import Card from '@/components/ui/Card'
import EmptyState from '@/components/ui/EmptyState'
import Input from '@/components/ui/Input'
import Label from '@/components/ui/Label'
import LoadingScreen from '@/components/ui/LoadingScreen'
import Modal from '@/components/ui/Modal'
import PageHeader from '@/components/ui/PageHeader'
import DocumentAccessPanel from '@/modules/access/components/DocumentAccessPanel'
import DocumentViewer from '@/modules/documents/components/DocumentViewer'
import DocumentCommentsPanel from '@/modules/comments/components/DocumentCommentsPanel'
import DocumentValidationsPanel from '@/modules/validations/components/DocumentValidationsPanel'
import { getErrorMessage } from '@/lib/api'
import { unwrapList } from '@/lib/apiHelpers'
import { documentAiCapabilities, fileFromBase64 } from '@/lib/documentAi'
import { fileVisual } from '@/lib/fileIcons'
import { formatBytes, formatDate, statusLabel } from '@/lib/format'
import { can, canAny } from '@/lib/permissions'
import { queryKeys } from '@/lib/queryClient'
import { downloadDocument, documentsApi } from '@/modules/documents/api'
import { documentTypesApi } from '@/modules/document-types/api'
import { favoritesApi } from '@/modules/favorites/api'
import { tagsApi } from '@/modules/tags/api'
import { useAuthStore } from '@/stores/authStore'

function statusTone(status) {
  if (status === 'valide' || status === 'publie') return 'success'
  if (status === 'en_validation' || status === 'propose') return 'warning'
  if (status === 'rejete') return 'danger'
  return 'neutral'
}

export default function DocumentDetailPage() {
  const { id } = useParams()
  const [searchParams] = useSearchParams()
  const user = useAuthStore((s) => s.user)
  const queryClient = useQueryClient()
  const confirm = useConfirm()
  const [tab, setTab] = useState(searchParams.get('tab') || 'overview')
  const [metaDraft, setMetaDraft] = useState(null)
  const [tagIdsDraft, setTagIdsDraft] = useState(null)
  const [versionFile, setVersionFile] = useState(null)
  const [content, setContent] = useState('')
  const [compareLeft, setCompareLeft] = useState('')
  const [compareRight, setCompareRight] = useState('')
  const [comparison, setComparison] = useState(null)
  const [ocrPreview, setOcrPreview] = useState('')
  const [showOcrModal, setShowOcrModal] = useState(false)
  const [enhancePreview, setEnhancePreview] = useState(null)
  const [showViewer, setShowViewer] = useState(false)
  const [editing, setEditing] = useState(false)

  const { data: document, isLoading, isError } = useQuery({
    queryKey: queryKeys.document(id),
    queryFn: () => documentsApi.get(id),
    enabled: Boolean(id),
  })

  useEffect(() => {
    setEditing(false)
    setMetaDraft(null)
    setTagIdsDraft(null)
    setOcrPreview('')
    setEnhancePreview(null)
  }, [id])

  const versionsQuery = useQuery({
    queryKey: queryKeys.documentVersions(id),
    queryFn: () => documentsApi.versions(id),
    enabled: Boolean(id) && tab === 'versions',
  })

  const typesQuery = useQuery({
    queryKey: queryKeys.documentTypes({}),
    queryFn: () => documentTypesApi.list(),
    enabled: editing && can(user, 'documents.update'),
  })

  const tagsQuery = useQuery({
    queryKey: queryKeys.tags,
    queryFn: tagsApi.list,
    enabled: editing && can(user, 'tags.manage'),
  })

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
    mutationFn: () => {
      const meta = metaDraft ?? {
        title: document.title ?? '',
        description: document.description ?? '',
        document_type_id: document.document_type?.id ?? '',
      }
      const tagIds = tagIdsDraft ?? (document.tags ?? []).map((t) => t.id)
      return documentsApi.update(id, {
        title: meta.title,
        description: meta.description || null,
        document_type_id: meta.document_type_id ? Number(meta.document_type_id) : null,
        tag_ids: tagIds,
      })
    },
    onSuccess: (res) => {
      toast.success(res.message)
      setMetaDraft(null)
      setTagIdsDraft(null)
      setEditing(false)
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
      setComparison(null)
      invalidateDoc()
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const compareVersions = useMutation({
    mutationFn: () =>
      documentsApi.compareVersions(id, Number(compareLeft), Number(compareRight)),
    onSuccess: (data) => setComparison(data),
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

  const proposeDocument = useMutation({
    mutationFn: () => documentsApi.propose(id),
    onSuccess: (res) => {
      toast.success(res.message)
      invalidateDoc()
      queryClient.invalidateQueries({ queryKey: queryKeys.documentValidations(id) })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const toggleFavorite = useMutation({
    mutationFn: () =>
      document.is_favorited
        ? favoritesApi.removeDocument(id)
        : favoritesApi.addDocument(id),
    onSuccess: (res) => {
      toast.success(res.message)
      invalidateDoc()
      queryClient.invalidateQueries({ queryKey: queryKeys.dashboard })
      queryClient.invalidateQueries({ queryKey: queryKeys.favorites })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const aiBrief = useMutation({
    mutationFn: () => documentsApi.aiSummarize(id),
    onSuccess: (res) => {
      toast.success(res.message)
      invalidateDoc()
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const aiOcr = useMutation({
    mutationFn: () => documentsApi.aiOcr(id),
    onSuccess: (res) => {
      toast.success(res.message)
      const text = res.ocr_text ?? ''
      setOcrPreview(text)
      setShowOcrModal(true)
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const saveOcrDoc = useMutation({
    mutationFn: () => documentsApi.saveOcrDocument(id, { text: ocrPreview }),
    onSuccess: (res) => {
      toast.success(res.message)
      setShowOcrModal(false)
      setTab('versions')
      invalidateDoc()
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const aiEnhance = useMutation({
    mutationFn: () => documentsApi.aiEnhance(id),
    onSuccess: (res) => {
      toast.success(res.message)
      const file = fileFromBase64(res.image_base64, res.file_name, res.mime_type)
      setEnhancePreview((prev) => {
        if (prev?.url) URL.revokeObjectURL(prev.url)
        return {
          file,
          mime: res.mime_type,
          url: URL.createObjectURL(file),
        }
      })
      invalidateDoc()
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const saveEnhance = useMutation({
    mutationFn: () => {
      const form = new FormData()
      form.append('file', enhancePreview.file)
      form.append('change_summary', 'Image éclaircie (IA)')
      return documentsApi.storeVersion(id, form)
    },
    onSuccess: (res) => {
      toast.success(res.message)
      setEnhancePreview((prev) => {
        if (prev?.url) URL.revokeObjectURL(prev.url)
        return null
      })
      setTab('versions')
      invalidateDoc()
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const aiBusy = aiBrief.isPending || aiOcr.isPending || aiEnhance.isPending

  async function handleDownload() {
    try {
      await downloadDocument(id, document?.current_version?.file_name ?? 'document')
    } catch (error) {
      toast.error(getErrorMessage(error, 'Téléchargement impossible.'))
    }
  }

  if (isLoading) return <LoadingScreen />
  if (isError || !document) return <EmptyState title="Document introuvable" />

  const version = document.current_version
  const visual = fileVisual(document)
  const VisualIcon = visual.Icon
  const ai = documentAiCapabilities(version?.mime_type, version?.extension)
  const canVersion = canAny(user, ['documents.update', 'versions.manage'])
  const types = unwrapList(typesQuery.data)
  const tags = unwrapList(tagsQuery.data)
  const versions = unwrapList(versionsQuery.data)

  const meta = metaDraft ?? {
    title: document.title ?? '',
    description: document.description ?? '',
    document_type_id: document.document_type?.id ?? '',
  }
  const tagIds = tagIdsDraft ?? (document.tags ?? []).map((t) => t.id)

  const setMeta = (next) => setMetaDraft(typeof next === 'function' ? next(meta) : next)
  const setTagIds = (next) => setTagIdsDraft(typeof next === 'function' ? next(tagIds) : next)

  const tabs = [
    { id: 'overview', label: 'Aperçu' },
    { id: 'versions', label: 'Versions' },
    document.is_editable ? { id: 'content', label: 'Édition' } : null,
    { id: 'comments', label: 'Commentaires' },
    { id: 'validations', label: 'Validations' },
    canAny(user, ['documents.share', 'accesses.manage']) || document.can_share
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
            <Button variant="secondary" size="sm" onClick={() => setShowViewer(true)}>
              <Eye className="h-4 w-4" />
              Aperçu
            </Button>
            <Button size="sm" onClick={handleDownload}>
              <Download className="h-4 w-4" />
              Télécharger
            </Button>
            {document.is_editable &&
            document.status !== 'archive' &&
            can(user, 'documents.update') ? (
              <Button size="sm" variant="secondary" onClick={() => setTab('content')}>
                <Pencil className="h-4 w-4" />
                Éditer
              </Button>
            ) : null}
            {document.can_propose ? (
              <Button
                size="sm"
                disabled={proposeDocument.isPending}
                onClick={() => proposeDocument.mutate()}
              >
                <Send className="h-4 w-4" />
                Proposer à validation
              </Button>
            ) : null}
            {can(user, 'documents.update') && document.status !== 'archive' ? (
              <Button size="sm" variant="secondary" onClick={() => setEditing(true)}>
                <PenLine className="h-4 w-4" />
                Modifier
              </Button>
            ) : null}
            <Button
              size="sm"
              variant="secondary"
              disabled={toggleFavorite.isPending}
              onClick={() => toggleFavorite.mutate()}
            >
              <Star
                className={`h-4 w-4 ${document.is_favorited ? 'fill-current text-amber-500' : ''}`}
              />
              {document.is_favorited ? 'Retirer' : 'Favori'}
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
                onClick={async () => {
                  const ok = await confirm({
                    title: 'Mettre à la corbeille',
                    description: 'Mettre ce document en corbeille ? Vous pourrez le restaurer ensuite.',
                    confirmLabel: 'Mettre à la corbeille',
                  })
                  if (ok) removeDoc.mutate()
                }}
              >
                <Trash2 className="h-4 w-4" />
              </Button>
            ) : null}
          </>
        }
      />

      <Tabs tabs={tabs} active={tab} onChange={setTab} />

      {document.can_propose ? (
        <div className="mb-4 rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm">
          <p className="font-medium">Proposition à validation</p>
          <p className="mt-1 text-xs text-muted-foreground">
            {document.requires_workflow
              ? 'Ce type de document exige un circuit de validation. Proposez-le, ou un responsable peut démarrer le workflow depuis l’onglet Validations.'
              : 'Seuls les documents proposés suivent un workflow. Cliquez sur « Proposer à validation » pour les envoyer aux responsables.'}
          </p>
        </div>
      ) : null}

      {document.status === 'archive' ? (
        <div className="mb-4 rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm">
          <p className="font-medium">Document archivé</p>
          <p className="mt-1 text-xs text-muted-foreground">
            Consultation et téléchargement uniquement. Il n’apparaît plus dans l’explorateur.
            Désarchiver le renvoie en brouillon.
          </p>
        </div>
      ) : null}

      <Modal
        open={showViewer}
        onClose={() => setShowViewer(false)}
        title="Aperçu du document"
        description={version?.file_name ?? document.title}
        size="full"
        className="max-h-[94vh]"
      >
        <DocumentViewer
          documentId={id}
          mimeType={version?.mime_type}
          extension={version?.extension}
          fileName={version?.file_name}
          heightClass="h-[min(78vh,720px)]"
          onDownload={handleDownload}
        />
      </Modal>

      <Modal
        open={showOcrModal}
        onClose={() => setShowOcrModal(false)}
        title="Texte extrait (OCR)"
        description="Vérifiez le texte, puis enregistrez-le comme nouvelle version de ce document."
        size="lg"
        footer={
          <>
            <Button type="button" variant="secondary" onClick={() => setShowOcrModal(false)}>
              Fermer
            </Button>
            {canVersion ? (
              <Button
                type="button"
                disabled={saveOcrDoc.isPending || !ocrPreview.trim()}
                onClick={() => saveOcrDoc.mutate()}
              >
                <Save className="h-4 w-4" />
                Enregistrer comme nouvelle version
              </Button>
            ) : null}
          </>
        }
      >
        <pre className="max-h-[50vh] overflow-auto whitespace-pre-wrap rounded-lg border border-border bg-muted/30 p-3 font-mono text-xs leading-relaxed">
          {ocrPreview || 'Aucun texte.'}
        </pre>
      </Modal>

      <Modal
        open={Boolean(enhancePreview)}
        onClose={() =>
          setEnhancePreview((prev) => {
            if (prev?.url) URL.revokeObjectURL(prev.url)
            return null
          })
        }
        title="Image éclaircie"
        description="Vérifiez le rendu, puis enregistrez-le comme nouvelle version de ce document."
        size="lg"
        footer={
          <>
            <Button
              type="button"
              variant="secondary"
              onClick={() =>
                setEnhancePreview((prev) => {
                  if (prev?.url) URL.revokeObjectURL(prev.url)
                  return null
                })
              }
            >
              Fermer
            </Button>
            {canVersion ? (
              <Button
                type="button"
                disabled={saveEnhance.isPending || !enhancePreview?.file}
                onClick={() => saveEnhance.mutate()}
              >
                <Save className="h-4 w-4" />
                Enregistrer comme nouvelle version
              </Button>
            ) : null}
          </>
        }
      >
        {enhancePreview?.url ? (
          <img
            src={enhancePreview.url}
            alt="Image éclaircie"
            className="mx-auto max-h-[60vh] max-w-full rounded-lg border border-border object-contain"
          />
        ) : null}
      </Modal>

      <div className="mt-6">
        {tab === 'overview' ? (
          <div className="space-y-4">
            <Card className="overflow-hidden p-0">
              <div className="flex min-w-0 items-start gap-4 p-5">
                <span
                  className="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl"
                  style={{ backgroundColor: `${visual.color}18`, color: visual.color }}
                >
                  <VisualIcon className="h-7 w-7" />
                </span>
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <Badge tone={statusTone(document.status)}>{statusLabel(document.status)}</Badge>
                    {document.is_editable ? <Badge tone="primary">Éditable en ligne</Badge> : null}
                    {document.is_favorited ? <Badge tone="warning">Favori</Badge> : null}
                  </div>
                  <p className="mt-2 truncate text-base font-semibold tracking-tight">
                    {version?.file_name ?? document.title}
                  </p>
                  <p className="mt-0.5 text-sm text-muted-foreground">
                    {[
                      visual.label,
                      version ? `v${version.version_number}` : null,
                      version ? formatBytes(version.size) : null,
                      document.folder?.name ? `Dossier · ${document.folder.name}` : null,
                    ]
                      .filter(Boolean)
                      .join(' · ')}
                  </p>
                </div>
              </div>

              <dl className="grid gap-px border-t border-border bg-border sm:grid-cols-2 lg:grid-cols-3">
                {[
                  { label: 'Référence', value: document.reference },
                  { label: 'Auteur', value: document.author?.name },
                  { label: 'Type documentaire', value: document.document_type?.name
                    ? `${document.document_type.name}${document.requires_workflow ? ' · validation obligatoire' : ''}`
                    : null },
                  { label: 'Confidentialité', value: document.confidentiality },
                  { label: 'Créé le', value: formatDate(document.created_at, true) },
                  {
                    label: 'Modifié le',
                    value: formatDate(document.updated_at ?? version?.created_at, true),
                  },
                ].map((row) => (
                  <div key={row.label} className="bg-background px-5 py-3">
                    <dt className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                      {row.label}
                    </dt>
                    <dd className="mt-1 truncate text-sm">{row.value || '—'}</dd>
                  </div>
                ))}
              </dl>

              {document.description ? (
                <div className="border-t border-border px-5 py-4">
                  <p className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                    Description
                  </p>
                  <p className="mt-1 whitespace-pre-wrap text-sm leading-relaxed">
                    {document.description}
                  </p>
                </div>
              ) : null}

              {(document.tags ?? []).length > 0 ? (
                <div className="flex flex-wrap gap-1.5 border-t border-border px-5 py-3">
                  {(document.tags ?? []).map((tag) => (
                    <Badge key={tag.id}>{tag.name}</Badge>
                  ))}
                </div>
              ) : null}
            </Card>

            <Modal
              open={editing}
              onClose={() => {
                setEditing(false)
                setMetaDraft(null)
                setTagIdsDraft(null)
              }}
              title="Modifier le document"
              description="Titre, description, type et tags."
              size="lg"
              footer={
                <>
                  <Button
                    type="button"
                    variant="secondary"
                    onClick={() => {
                      setEditing(false)
                      setMetaDraft(null)
                      setTagIdsDraft(null)
                    }}
                  >
                    Annuler
                  </Button>
                  <Button
                    type="submit"
                    form="edit-doc-meta-form"
                    disabled={saveMeta.isPending || !meta.title.trim()}
                  >
                    Enregistrer
                  </Button>
                </>
              }
            >
              <form
                id="edit-doc-meta-form"
                className="space-y-3"
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
                    autoFocus
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
                {document.is_editable ? (
                  <p className="text-xs text-muted-foreground">
                    Pour modifier le contenu du fichier, utilisez le bouton Éditer (onglet Édition).
                  </p>
                ) : (
                  <p className="text-xs text-muted-foreground">
                    Édition en ligne indisponible pour .{version?.extension ?? 'ce format'} — réuploadez
                    une version texte (.txt, .md, .csv…) ou utilisez un fichier Office hors ligne.
                  </p>
                )}
                {can(user, 'tags.manage') ? (
                  <div>
                    <Label>Tags</Label>
                    <div className="mt-2 flex max-h-40 flex-wrap gap-2 overflow-y-auto">
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
                      {!tags.length ? (
                        <p className="text-xs text-muted-foreground">Aucun tag disponible.</p>
                      ) : null}
                    </div>
                  </div>
                ) : null}
              </form>
            </Modal>

            {ai.any ? (
              <Card>
                <div className="flex items-center gap-2">
                  <Sparkles className="h-4 w-4 text-muted-foreground" />
                  <h2 className="text-sm font-semibold">Assistant IA</h2>
                </div>
                <p className="mt-1 text-xs text-muted-foreground">
                  {ai.enhance
                    ? 'Analyse, OCR ou éclaircissement. OCR et éclaircissement ne sont enregistrés que si vous créez une nouvelle version.'
                    : ai.ocr
                      ? 'Analyse du contenu, ou extraction OCR (nouvelle version).'
                      : 'Analyse du contenu de ce fichier texte.'}
                </p>
                <div className="mt-3 flex flex-wrap gap-2">
                  {ai.brief ? (
                    <Button
                      size="sm"
                      variant="secondary"
                      disabled={aiBusy}
                      onClick={() => aiBrief.mutate()}
                    >
                      <Sparkles className="h-4 w-4" />
                      Analyser
                    </Button>
                  ) : null}
                  {ai.ocr ? (
                    <Button
                      size="sm"
                      variant="secondary"
                      disabled={aiBusy}
                      onClick={() => aiOcr.mutate()}
                    >
                      <ScanText className="h-4 w-4" />
                      Extraire le texte (OCR)
                    </Button>
                  ) : null}
                  {ai.enhance ? (
                    <Button
                      size="sm"
                      variant="secondary"
                      disabled={aiBusy}
                      onClick={() => aiEnhance.mutate()}
                    >
                      <SunMedium className="h-4 w-4" />
                      Éclaircir l’image
                    </Button>
                  ) : null}
                </div>

                {document.summary ? (
                  <div className="mt-4">
                    <p className="text-xs font-medium text-muted-foreground">Fiche IA</p>
                    <p className="mt-1 whitespace-pre-wrap text-sm">{document.summary}</p>
                  </div>
                ) : document.ai_analysis ? (
                  <div className="mt-4">
                    <p className="text-xs font-medium text-muted-foreground">Fiche IA</p>
                    <p className="mt-1 whitespace-pre-wrap text-sm">{document.ai_analysis}</p>
                  </div>
                ) : null}

                {document.ai_processed_at ? (
                  <p className="mt-3 text-[11px] text-muted-foreground">
                    Dernière action IA : {formatDate(document.ai_processed_at, true)}
                  </p>
                ) : null}
              </Card>
            ) : null}
          </div>
        ) : null}

        {tab === 'versions' ? (
          <div className="space-y-4">
            {canAny(user, ['documents.update', 'versions.manage']) &&
            document.status !== 'archive' ? (
              <Card>
                <Label>Nouvelle version (réupload)</Label>
                <p className="mt-1 text-xs text-muted-foreground">
                  La version courante est verrouillée automatiquement avant création de la suivante.
                </p>
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

            {versions.length >= 2 ? (
              <Card>
                <h2 className="text-sm font-semibold">Comparer deux versions</h2>
                <div className="mt-3 flex flex-wrap gap-2">
                  <select
                    className="h-11 min-w-[140px] rounded-lg border border-border bg-background px-3 text-sm"
                    value={compareLeft}
                    onChange={(e) => setCompareLeft(e.target.value)}
                  >
                    <option value="">Version A</option>
                    {versions.map((v) => (
                      <option key={`l-${v.id}`} value={v.id}>
                        v{v.version_number}
                      </option>
                    ))}
                  </select>
                  <select
                    className="h-11 min-w-[140px] rounded-lg border border-border bg-background px-3 text-sm"
                    value={compareRight}
                    onChange={(e) => setCompareRight(e.target.value)}
                  >
                    <option value="">Version B</option>
                    {versions.map((v) => (
                      <option key={`r-${v.id}`} value={v.id}>
                        v{v.version_number}
                      </option>
                    ))}
                  </select>
                  <Button
                    size="sm"
                    variant="secondary"
                    disabled={
                      !compareLeft ||
                      !compareRight ||
                      compareLeft === compareRight ||
                      compareVersions.isPending
                    }
                    onClick={() => compareVersions.mutate()}
                  >
                    <Eye className="h-4 w-4" />
                    Comparer
                  </Button>
                </div>

                {comparison ? (
                  <div className="mt-4 space-y-4">
                    <div className="grid gap-3 sm:grid-cols-2">
                      {[comparison.left, comparison.right].map((v, idx) => (
                        <div key={v.id} className="rounded-lg border border-border p-3 text-sm">
                          <p className="font-medium">
                            {idx === 0 ? 'A' : 'B'} — v{v.version_number}
                          </p>
                          <p className="text-xs text-muted-foreground">
                            {v.creator?.name ?? '—'} · {formatDate(v.created_at, true)}
                          </p>
                          <p className="mt-1 text-xs">{v.file_name}</p>
                          <p className="text-xs text-muted-foreground">
                            {formatBytes(v.size)} · {v.is_locked ? 'verrouillée' : 'courante'}
                          </p>
                        </div>
                      ))}
                    </div>

                    {Object.keys(comparison.metadata_diff ?? {}).length > 0 ? (
                      <div>
                        <p className="text-sm font-medium">Différences de métadonnées</p>
                        <ul className="mt-2 space-y-1 text-xs">
                          {Object.entries(comparison.metadata_diff).map(([key, value]) => (
                            <li key={key} className="rounded border border-border px-2 py-1.5">
                              <span className="font-medium">{key}</span>
                              <span className="text-muted-foreground">
                                {' '}
                                : {String(value.left ?? '—')} → {String(value.right ?? '—')}
                              </span>
                            </li>
                          ))}
                        </ul>
                      </div>
                    ) : (
                      <p className="text-xs text-muted-foreground">Métadonnées identiques.</p>
                    )}

                    {!comparison.content_comparable ? (
                      <p className="text-xs text-muted-foreground">
                        Différence de contenu non disponible pour ce type de fichier (binaire).
                      </p>
                    ) : comparison.content_identical ? (
                      <p className="text-xs text-muted-foreground">Contenu identique.</p>
                    ) : (
                      <div>
                        <p className="text-sm font-medium">Différence de contenu</p>
                        <pre className="mt-2 max-h-72 overflow-auto rounded-lg border border-border bg-muted/30 p-3 text-xs leading-5">
                          {(comparison.content_diff ?? []).map((line, i) => (
                            <div
                              key={`${line.type}-${i}`}
                              className={
                                line.type === 'add'
                                  ? 'bg-emerald-500/15 text-emerald-800'
                                  : line.type === 'remove'
                                    ? 'bg-red-500/15 text-red-800'
                                    : 'text-muted-foreground'
                              }
                            >
                              {line.type === 'add' ? '+ ' : line.type === 'remove' ? '- ' : '  '}
                              {line.text}
                            </div>
                          ))}
                        </pre>
                      </div>
                    )}
                  </div>
                ) : null}
              </Card>
            ) : null}

            {versionsQuery.isLoading ? (
              <LoadingScreen />
            ) : versions.length === 0 ? (
              <EmptyState title="Aucune version" />
            ) : (
              <ul className="divide-y divide-border rounded-xl border border-border bg-background">
                {versions.map((v) => {
                  const isCurrent = document.current_version?.id === v.id
                  return (
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
                      <div className="flex gap-1.5">
                        {isCurrent ? <Badge tone="success">Courante</Badge> : null}
                        {v.is_locked ? <Badge tone="neutral">Verrouillée</Badge> : null}
                      </div>
                    </li>
                  )
                })}
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
            documentWorkflow={document.workflow}
            subjectToWorkflow={document.subject_to_workflow}
            recommendsWorkflow={document.recommends_workflow}
            canPropose={document.can_propose}
            requiresWorkflow={document.requires_workflow}
            canStartWorkflow={document.can_start_workflow}
            onUpdated={invalidateDoc}
          />
        ) : null}
        {tab === 'access' ? <DocumentAccessPanel documentId={id} /> : null}
      </div>
    </>
  )
}
