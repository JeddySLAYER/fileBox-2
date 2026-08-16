import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Download, Loader2, Save, ScanText, Sparkles, SunMedium, Upload } from 'lucide-react'
import { toast } from 'sonner'
import Button from '@/components/ui/Button'
import Card from '@/components/ui/Card'
import Input from '@/components/ui/Input'
import Label from '@/components/ui/Label'
import Modal from '@/components/ui/Modal'
import PageHeader from '@/components/ui/PageHeader'
import { getErrorMessage } from '@/lib/api'
import { unwrapList } from '@/lib/apiHelpers'
import { cn } from '@/lib/cn'
import {
  AI_FILE_ACCEPT,
  BRIEF_EXTENSIONS,
  documentAiCapabilities,
  downloadBlob,
  ENHANCE_EXTENSIONS,
  fileFromBase64,
  OCR_EXTENSIONS,
  textFileFromBrief,
  textFileFromOcr,
} from '@/lib/documentAi'
import { can } from '@/lib/permissions'
import { queryKeys } from '@/lib/queryClient'
import { documentsApi } from '@/modules/documents/api'
import { documentTypesApi } from '@/modules/document-types/api'
import LocalFilePreview, { titleFromFile } from '@/modules/documents/components/LocalFilePreview'
import { foldersApi } from '@/modules/folders/api'
import { tagsApi } from '@/modules/tags/api'
import { useAuthStore } from '@/stores/authStore'

function flattenFolders(nodes, prefix = '') {
  const out = []
  for (const n of nodes || []) {
    const label = prefix ? `${prefix} / ${n.name}` : n.name
    out.push({ id: n.id, name: label })
    if (n.children?.length) out.push(...flattenFolders(n.children, label))
  }
  return out
}

export default function AiToolsPage() {
  const user = useAuthStore((s) => s.user)
  const queryClient = useQueryClient()
  const canCreate = can(user, 'documents.create')
  const canPickTags = can(user, 'tags.manage')

  const [sourceFile, setSourceFile] = useState(null)
  const [dropActive, setDropActive] = useState(false)
  const [ocrText, setOcrText] = useState('')
  const [analysisText, setAnalysisText] = useState('')
  const [enhancedFile, setEnhancedFile] = useState(null)
  const [showSave, setShowSave] = useState(false)
  const [saveTitle, setSaveTitle] = useState('')
  const [saveFolderId, setSaveFolderId] = useState('')
  const [saveDescription, setSaveDescription] = useState('')
  const [saveTagIds, setSaveTagIds] = useState([])
  const [saveTypeId, setSaveTypeId] = useState('')

  const treeQuery = useQuery({
    queryKey: queryKeys.folders({ tree: true }),
    queryFn: () => foldersApi.tree(),
    enabled: showSave,
  })
  const tagsQuery = useQuery({
    queryKey: queryKeys.tags,
    queryFn: tagsApi.list,
    enabled: canPickTags && showSave,
  })

  const typesQuery = useQuery({
    queryKey: queryKeys.documentTypes({}),
    queryFn: () => documentTypesApi.list(),
    enabled: showSave,
  })
  const folders = useMemo(() => flattenFolders(unwrapList(treeQuery.data)), [treeQuery.data])
  const tags = unwrapList(tagsQuery.data)
  const docTypes = unwrapList(typesQuery.data)
  const ai = sourceFile
    ? documentAiCapabilities(sourceFile.type, sourceFile.name.split('.').pop())
    : { ocr: false, enhance: false, brief: false, any: false }
  const resultFile = enhancedFile
    || (ocrText ? textFileFromOcr(ocrText, sourceFile?.name) : null)
    || (analysisText ? textFileFromBrief(analysisText, sourceFile?.name) : null)
  const hasResult = Boolean(resultFile)

  const previewAi = useMutation({
    mutationFn: async ({ action, file }) => {
      const form = new FormData()
      form.append('file', file)
      form.append('action', action)
      const res = await documentsApi.aiPreview(form)
      return { res, action, file }
    },
    onSuccess: ({ res, action, file }) => {
      toast.success(res.message)
      if (action === 'ocr') {
        setOcrText(res.ocr_text ?? '')
        setAnalysisText('')
        setEnhancedFile(null)
        setSaveTitle(titleFromFile(file))
        return
      }
      if (action === 'analyze') {
        setAnalysisText(res.summary ?? '')
        setOcrText('')
        setEnhancedFile(null)
        setSaveTitle(`${titleFromFile(file)} — analyse`)
        return
      }
      setOcrText('')
      setAnalysisText('')
      setEnhancedFile(fileFromBase64(res.image_base64, res.file_name, res.mime_type))
      setSaveTitle(titleFromFile(file).replace(/-eclairci$/i, ''))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  const saveDocument = useMutation({
    mutationFn: () => {
      if (!resultFile || !saveFolderId) throw new Error('Choisissez un dossier.')
      const form = new FormData()
      form.append('title', saveTitle.trim() || titleFromFile(resultFile))
      if (saveDescription.trim()) form.append('description', saveDescription.trim())
      form.append('folder_id', String(saveFolderId))
      if (saveTypeId) form.append('document_type_id', String(saveTypeId))
      form.append('file', resultFile)
      saveTagIds.forEach((id) => form.append('tag_ids[]', String(id)))
      return documentsApi.create(form)
    },
    onSuccess: () => {
      toast.success('Document enregistré')
      setShowSave(false)
      queryClient.invalidateQueries({ queryKey: ['documents'] })
      queryClient.invalidateQueries({ queryKey: queryKeys.dashboard })
    },
    onError: (error) => toast.error(getErrorMessage(error, error.message)),
  })

  function applyFile(file) {
    if (!file) return
    setSourceFile(file)
    setOcrText('')
    setAnalysisText('')
    setEnhancedFile(null)
    setSaveTitle(titleFromFile(file))
  }

  function openSave() {
    setSaveTitle((current) => current.trim() || titleFromFile(resultFile))
    setShowSave(true)
  }

  function downloadResult() {
    if (!resultFile) return
    downloadBlob(resultFile, resultFile.name)
  }

  return (
    <>
      <PageHeader
        title="Traitement"
        description="Analysez, extrayez le texte ou éclaircissez un fichier sans l’enregistrer. Le résultat s’affiche ici à la fin du traitement."
      />

      <Card className="max-w-3xl">
        <h2 className="text-sm font-semibold">Fichier</h2>
        <p className="mt-1 text-xs text-muted-foreground">
          Analyser : {BRIEF_EXTENSIONS.join(', ')}. OCR : {OCR_EXTENSIONS.join(', ')}. Éclaircir :{' '}
          {ENHANCE_EXTENSIONS.join(', ')}.
        </p>
        <div
          className={cn(
            'mt-3 rounded-xl border-2 border-dashed px-4 py-8 text-center transition-colors',
            dropActive ? 'border-primary bg-primary/10' : 'border-primary/40 bg-primary/5',
          )}
          onDragOver={(e) => {
            e.preventDefault()
            setDropActive(true)
          }}
          onDragLeave={() => setDropActive(false)}
          onDrop={(e) => {
            e.preventDefault()
            setDropActive(false)
            applyFile(e.dataTransfer?.files?.[0] ?? null)
          }}
        >
          <Upload className="mx-auto mb-3 h-8 w-8 text-primary" />
          <p className="text-sm font-medium">Glissez-déposez un fichier</p>
          <input
            id="ai-file"
            className="hidden"
            type="file"
            accept={AI_FILE_ACCEPT}
            onChange={(e) => applyFile(e.target.files?.[0] ?? null)}
          />
          <Button as="label" htmlFor="ai-file" size="lg" className="mt-4 cursor-pointer">
            <Upload className="h-5 w-5" />
            {sourceFile ? 'Changer de fichier' : 'Choisir un fichier'}
          </Button>
        </div>

        {sourceFile && !ai.any ? (
          <p className="mt-3 rounded-lg border border-border bg-muted/30 p-3 text-xs text-muted-foreground">
            Ce format n’est pas pris en charge. Utilisez un fichier texte ({BRIEF_EXTENSIONS.filter((e) => !OCR_EXTENSIONS.includes(e)).join(', ')})
            , un PDF ou une image.
          </p>
        ) : null}

        {sourceFile && ai.any ? (
          <div className="mt-3 flex flex-wrap gap-2">
            {ai.brief ? (
              <Button
                type="button"
                variant="secondary"
                disabled={previewAi.isPending}
                onClick={() => previewAi.mutate({ action: 'analyze', file: sourceFile })}
              >
                <Sparkles className="h-4 w-4" />
                Analyser
              </Button>
            ) : null}
            {ai.ocr ? (
              <Button
                type="button"
                variant="secondary"
                disabled={previewAi.isPending}
                onClick={() => previewAi.mutate({ action: 'ocr', file: sourceFile })}
              >
                <ScanText className="h-4 w-4" />
                Extraire le texte
              </Button>
            ) : null}
            {ai.enhance ? (
              <Button
                type="button"
                variant="secondary"
                disabled={previewAi.isPending}
                onClick={() => previewAi.mutate({ action: 'enhance', file: sourceFile })}
              >
                <SunMedium className="h-4 w-4" />
                Éclaircir l’image
              </Button>
            ) : null}
          </div>
        ) : null}

        {sourceFile ? (
          <div className="relative mt-4">
            {previewAi.isPending ? (
              <div className="flex min-h-[12rem] flex-col items-center justify-center gap-2 rounded-lg border border-border bg-muted/30 p-6 text-sm text-muted-foreground">
                <Loader2 className="h-6 w-6 animate-spin text-primary" />
                Traitement en cours…
              </div>
            ) : analysisText ? (
              <div>
                <p className="text-xs font-medium text-muted-foreground">Fiche IA</p>
                <p className="mt-2 whitespace-pre-wrap rounded-lg border border-border bg-muted/20 p-4 text-sm leading-relaxed">
                  {analysisText}
                </p>
              </div>
            ) : ocrText ? (
              <pre className="max-h-[28rem] overflow-auto whitespace-pre-wrap rounded-lg border border-border bg-muted/30 p-3 font-mono text-xs leading-relaxed">
                {ocrText}
              </pre>
            ) : enhancedFile ? (
              <LocalFilePreview file={enhancedFile} caption="Image éclaircie (non enregistrée)." />
            ) : (
              <LocalFilePreview file={sourceFile} />
            )}
          </div>
        ) : null}

        {hasResult && !previewAi.isPending ? (
          <div className="mt-3 flex flex-wrap gap-2">
            <Button type="button" variant="secondary" onClick={downloadResult}>
              <Download className="h-4 w-4" />
              Télécharger
            </Button>
            {canCreate ? (
              <Button type="button" onClick={openSave}>
                <Save className="h-4 w-4" />
                Enregistrer dans FileBox
              </Button>
            ) : null}
          </div>
        ) : null}
      </Card>

      <Modal
        open={showSave}
        onClose={() => setShowSave(false)}
        title="Enregistrer le résultat"
        description="Le fichier original n’est pas conservé : seul le résultat IA sera stocké."
        footer={
          <>
            <Button type="button" variant="secondary" onClick={() => setShowSave(false)}>
              Annuler
            </Button>
            <Button
              type="submit"
              form="ai-save-form"
              disabled={saveDocument.isPending || !saveFolderId || !saveTitle.trim()}
            >
              Enregistrer
            </Button>
          </>
        }
      >
        <form
          id="ai-save-form"
          className="space-y-3"
          onSubmit={(e) => {
            e.preventDefault()
            saveDocument.mutate()
          }}
        >
          <div>
            <Label htmlFor="ai-save-title">Titre</Label>
            <Input
              id="ai-save-title"
              className="mt-1"
              value={saveTitle}
              onChange={(e) => setSaveTitle(e.target.value)}
              required
            />
          </div>
          <div>
            <Label htmlFor="ai-save-folder">Dossier</Label>
            <select
              id="ai-save-folder"
              className="mt-1 h-11 w-full rounded-lg border border-border bg-background px-3 text-sm"
              value={saveFolderId}
              onChange={(e) => setSaveFolderId(e.target.value)}
              required
            >
              <option value="">Choisir un dossier…</option>
              {folders.map((f) => (
                <option key={f.id} value={f.id}>
                  {f.name}
                </option>
              ))}
            </select>
          </div>
          <div>
            <Label htmlFor="ai-save-type">Type de document</Label>
            <select
              id="ai-save-type"
              className="mt-1 h-11 w-full rounded-lg border border-border bg-background px-3 text-sm"
              value={saveTypeId}
              onChange={(e) => setSaveTypeId(e.target.value)}
            >
              <option value="">— Aucun —</option>
              {docTypes.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.name}
                  {t.requires_workflow ? ' · validation obligatoire' : ''}
                </option>
              ))}
            </select>
          </div>
          <div>
            <Label htmlFor="ai-save-desc">Description (facultatif)</Label>
            <Input
              id="ai-save-desc"
              className="mt-1"
              value={saveDescription}
              onChange={(e) => setSaveDescription(e.target.value)}
            />
          </div>
          {canPickTags ? (
            <div>
              <Label>Tags (facultatif)</Label>
              <div className="mt-2 flex max-h-40 flex-wrap gap-2 overflow-y-auto">
                {tags.map((tag) => (
                  <label
                    key={tag.id}
                    className="flex cursor-pointer items-center gap-1 rounded border border-border px-2 py-1 text-xs"
                  >
                    <input
                      type="checkbox"
                      checked={saveTagIds.includes(tag.id)}
                      onChange={() =>
                        setSaveTagIds((prev) =>
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
        </form>
      </Modal>
    </>
  )
}
