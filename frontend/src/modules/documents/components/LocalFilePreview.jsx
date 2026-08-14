import { useEffect, useState } from 'react'
import { FileWarning } from 'lucide-react'
import { fileVisual } from '@/lib/fileIcons'
import { formatBytes } from '@/lib/format'
import { viewerKind } from '@/modules/documents/components/DocumentViewer'
import { cn } from '@/lib/cn'

function stemFromFileName(name) {
  return String(name ?? '').replace(/\.[^.]+$/, '')
}

export function titleFromFile(file) {
  return stemFromFileName(file?.name) || 'Document'
}

/**
 * Aperçu local d’un fichier choisi (création), sans passer par l’API.
 */
export default function LocalFilePreview({ file, className, caption }) {
  const [text, setText] = useState('')
  const [url, setUrl] = useState(null)

  const ext = String(file?.name ?? '')
    .split('.')
    .pop()
    ?.toLowerCase()
  const kind = file ? viewerKind(file.type, ext) : 'unsupported'
  const visual = fileVisual({ current_version: { extension: ext, file_name: file?.name } })
  const Icon = visual.Icon

  useEffect(() => {
    if (!file) {
      setUrl(null)
      setText('')
      return undefined
    }

    const objectUrl = URL.createObjectURL(file)
    setUrl(objectUrl)

    if (kind === 'text') {
      file.text().then(setText).catch(() => setText(''))
    } else {
      setText('')
    }

    return () => URL.revokeObjectURL(objectUrl)
  }, [file, kind])

  if (!file) return null

  return (
    <div className={cn('overflow-hidden rounded-xl border border-border bg-muted/20', className)}>
      <div className="flex items-center gap-2 border-b border-border bg-background px-3 py-2">
        <span
          className="flex h-8 w-8 items-center justify-center rounded-lg"
          style={{ backgroundColor: `${visual.color}18`, color: visual.color }}
        >
          <Icon className="h-4 w-4" />
        </span>
        <div className="min-w-0">
          <p className="truncate text-sm font-medium">{file.name}</p>
          <p className="text-[11px] text-muted-foreground">
            {visual.label} · {formatBytes(file.size)}
          </p>
          {caption ? <p className="text-[11px] text-primary">{caption}</p> : null}
        </div>
      </div>

      <div className="flex max-h-56 min-h-[8rem] items-center justify-center overflow-auto bg-muted/30">
        {kind === 'image' && url ? (
          <img src={url} alt={file.name} className="max-h-56 max-w-full object-contain p-2" />
        ) : null}
        {kind === 'pdf' && url ? (
          <iframe title={file.name} src={url} className="h-56 w-full border-0 bg-white" />
        ) : null}
        {kind === 'text' ? (
          <pre className="m-0 h-56 w-full overflow-auto whitespace-pre-wrap break-words p-3 font-mono text-xs">
            {text || '(fichier vide)'}
          </pre>
        ) : null}
        {kind === 'unsupported' ? (
          <div className="flex flex-col items-center gap-2 px-4 py-6 text-center text-muted-foreground">
            <FileWarning className="h-8 w-8" />
            <p className="text-xs">
              Aperçu in-app indisponible pour ce format. Le fichier sera bien enregistré à
              l’upload.
            </p>
          </div>
        ) : null}
      </div>
    </div>
  )
}
