import { useEffect, useMemo, useState } from 'react'
import { Document, Page, pdfjs } from 'react-pdf'
import {
  ChevronLeft,
  ChevronRight,
  Download,
  FileWarning,
  Loader2,
  ZoomIn,
  ZoomOut,
} from 'lucide-react'
import api from '@/lib/api'
import Button from '@/components/ui/Button'
import { cn } from '@/lib/cn'
import { fileVisual } from '@/lib/fileIcons'
import 'react-pdf/dist/Page/AnnotationLayer.css'
import 'react-pdf/dist/Page/TextLayer.css'

pdfjs.GlobalWorkerOptions.workerSrc = new URL(
  'pdfjs-dist/build/pdf.worker.min.mjs',
  import.meta.url,
).toString()

const TEXT_EXTS = new Set([
  'txt',
  'md',
  'markdown',
  'csv',
  'tsv',
  'json',
  'xml',
  'html',
  'htm',
  'css',
  'js',
  'log',
])

export function viewerKind(mimeType, extension) {
  const mime = String(mimeType ?? '').toLowerCase()
  const ext = String(extension ?? '')
    .replace(/^\./, '')
    .toLowerCase()

  if (mime === 'application/pdf' || ext === 'pdf') return 'pdf'
  if (mime.startsWith('image/') || ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'bmp'].includes(ext)) {
    return 'image'
  }
  if (mime.startsWith('text/') || TEXT_EXTS.has(ext)) return 'text'
  return 'unsupported'
}

/**
 * Visionneuse in-app : PDF, image, texte. Autres formats → téléchargement.
 */
export default function DocumentViewer({
  documentId,
  mimeType,
  extension,
  fileName,
  onDownload,
  className,
  heightClass = 'h-[min(70vh,640px)]',
}) {
  const kind = viewerKind(mimeType, extension)
  const visual = fileVisual({ current_version: { extension, file_name: fileName } })
  const [blobUrl, setBlobUrl] = useState(null)
  const [textContent, setTextContent] = useState('')
  const [loading, setLoading] = useState(kind !== 'unsupported')
  const [error, setError] = useState(null)
  const [page, setPage] = useState(1)
  const [numPages, setNumPages] = useState(0)
  const [scale, setScale] = useState(1)

  useEffect(() => {
    if (!documentId || kind === 'unsupported') {
      setLoading(false)
      return undefined
    }

    let revoked = false
    let objectUrl = null

    setLoading(true)
    setError(null)
    setBlobUrl(null)
    setTextContent('')
    setPage(1)
    setNumPages(0)

    api
      .get(`/documents/${documentId}/preview`, { responseType: 'blob' })
      .then(async (response) => {
        if (revoked) return
        const contentType = String(response.headers['content-type'] ?? '')
        if (contentType.includes('application/json')) {
          const payload = JSON.parse(await response.data.text())
          throw new Error(payload.message || 'Prévisualisation indisponible.')
        }
        if (kind === 'text') {
          setTextContent(await response.data.text())
        } else {
          objectUrl = URL.createObjectURL(response.data)
          setBlobUrl(objectUrl)
        }
      })
      .catch((err) => {
        if (revoked) return
        const msg =
          err?.response?.data instanceof Blob
            ? 'Prévisualisation impossible.'
            : err?.message || 'Prévisualisation impossible.'
        setError(msg)
      })
      .finally(() => {
        if (!revoked) setLoading(false)
      })

    return () => {
      revoked = true
      if (objectUrl) URL.revokeObjectURL(objectUrl)
    }
  }, [documentId, kind])

  const toolbar = useMemo(() => {
    if (kind === 'pdf' && numPages > 0) {
      return (
        <div className="flex flex-wrap items-center gap-1">
          <Button
            type="button"
            size="sm"
            variant="ghost"
            disabled={page <= 1}
            onClick={() => setPage((p) => Math.max(1, p - 1))}
            aria-label="Page précédente"
          >
            <ChevronLeft className="h-4 w-4" />
          </Button>
          <span className="min-w-[4.5rem] text-center text-xs text-muted-foreground">
            {page} / {numPages}
          </span>
          <Button
            type="button"
            size="sm"
            variant="ghost"
            disabled={page >= numPages}
            onClick={() => setPage((p) => Math.min(numPages, p + 1))}
            aria-label="Page suivante"
          >
            <ChevronRight className="h-4 w-4" />
          </Button>
          <span className="mx-1 h-4 w-px bg-border" />
          <Button
            type="button"
            size="sm"
            variant="ghost"
            onClick={() => setScale((s) => Math.max(0.5, Number((s - 0.1).toFixed(1))))}
            aria-label="Zoom arrière"
          >
            <ZoomOut className="h-4 w-4" />
          </Button>
          <span className="min-w-[3rem] text-center text-xs text-muted-foreground">
            {Math.round(scale * 100)}%
          </span>
          <Button
            type="button"
            size="sm"
            variant="ghost"
            onClick={() => setScale((s) => Math.min(2.5, Number((s + 0.1).toFixed(1))))}
            aria-label="Zoom avant"
          >
            <ZoomIn className="h-4 w-4" />
          </Button>
        </div>
      )
    }
    if (kind === 'image') {
      return (
        <div className="flex items-center gap-1">
          <Button
            type="button"
            size="sm"
            variant="ghost"
            onClick={() => setScale((s) => Math.max(0.4, Number((s - 0.15).toFixed(2))))}
          >
            <ZoomOut className="h-4 w-4" />
          </Button>
          <span className="min-w-[3rem] text-center text-xs text-muted-foreground">
            {Math.round(scale * 100)}%
          </span>
          <Button
            type="button"
            size="sm"
            variant="ghost"
            onClick={() => setScale((s) => Math.min(3, Number((s + 0.15).toFixed(2))))}
          >
            <ZoomIn className="h-4 w-4" />
          </Button>
        </div>
      )
    }
    return null
  }, [kind, numPages, page, scale])

  return (
    <div
      className={cn(
        'flex flex-col overflow-hidden rounded-xl border border-border bg-muted/20',
        className,
      )}
    >
      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border bg-background/80 px-3 py-2">
        <div className="flex min-w-0 items-center gap-2">
          <span
            className="flex h-8 w-8 items-center justify-center rounded-lg"
            style={{ backgroundColor: `${visual.color}18`, color: visual.color }}
          >
            <visual.Icon className="h-4 w-4" />
          </span>
          <div className="min-w-0">
            <p className="truncate text-sm font-medium">{fileName || 'Document'}</p>
            <p className="text-[11px] text-muted-foreground">{visual.label}</p>
          </div>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {toolbar}
          {onDownload ? (
            <Button type="button" size="sm" variant="secondary" onClick={onDownload}>
              <Download className="h-4 w-4" />
              Télécharger
            </Button>
          ) : null}
        </div>
      </div>

      <div className={cn('relative flex min-h-0 flex-1 items-center justify-center overflow-auto', heightClass)}>
        {loading ? (
          <div className="flex flex-col items-center gap-2 text-muted-foreground">
            <Loader2 className="h-8 w-8 animate-spin" />
            <p className="text-sm">Chargement de l’aperçu…</p>
          </div>
        ) : null}

        {!loading && error ? (
          <div className="flex max-w-sm flex-col items-center gap-3 px-6 text-center">
            <FileWarning className="h-10 w-10 text-amber-500" />
            <p className="text-sm text-muted-foreground">{error}</p>
            {onDownload ? (
              <Button type="button" size="sm" onClick={onDownload}>
                <Download className="h-4 w-4" />
                Télécharger le fichier
              </Button>
            ) : null}
          </div>
        ) : null}

        {!loading && !error && kind === 'unsupported' ? (
          <div className="flex max-w-md flex-col items-center gap-3 px-6 py-10 text-center">
            <span
              className="flex h-16 w-16 items-center justify-center rounded-2xl"
              style={{ backgroundColor: `${visual.color}18`, color: visual.color }}
            >
              <visual.Icon className="h-8 w-8" />
            </span>
            <div>
              <p className="text-sm font-medium">Aperçu non disponible pour ce format</p>
              <p className="mt-1 text-xs text-muted-foreground">
                Les fichiers Office et archives se consultent hors ligne. Téléchargez le document
                pour l’ouvrir avec l’application adaptée.
              </p>
            </div>
            {onDownload ? (
              <Button type="button" size="sm" onClick={onDownload}>
                <Download className="h-4 w-4" />
                Télécharger
              </Button>
            ) : null}
          </div>
        ) : null}

        {!loading && !error && kind === 'image' && blobUrl ? (
          <img
            src={blobUrl}
            alt={fileName || 'Aperçu'}
            className="max-h-full max-w-full object-contain transition-transform"
            style={{ transform: `scale(${scale})` }}
          />
        ) : null}

        {!loading && !error && kind === 'text' ? (
          <pre className="m-0 h-full w-full overflow-auto whitespace-pre-wrap break-words bg-background p-4 font-mono text-xs leading-relaxed text-foreground">
            {textContent || '(fichier vide)'}
          </pre>
        ) : null}

        {!loading && !error && kind === 'pdf' && blobUrl ? (
          <Document
            file={blobUrl}
            loading={
              <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <Loader2 className="h-5 w-5 animate-spin" />
                Lecture du PDF…
              </div>
            }
            onLoadSuccess={({ numPages: n }) => setNumPages(n)}
            onLoadError={() => setError('Impossible de lire ce PDF.')}
            className="flex justify-center p-4"
          >
            <Page
              pageNumber={page}
              scale={scale}
              renderTextLayer
              renderAnnotationLayer
              className="shadow-soft"
            />
          </Document>
        ) : null}
      </div>
    </div>
  )
}
