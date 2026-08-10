/** Extensions / MIME pris en charge par Gemini côté FileBox. */
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

const MULTIMODAL_EXTS = new Set(['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'])

function normalizeExt(extension) {
  return String(extension ?? '')
    .replace(/^\./, '')
    .toLowerCase()
}

/**
 * Quelles actions IA ont du sens pour ce fichier (aligné DocumentAiService).
 */
export function documentAiCapabilities(mimeType, extension) {
  const mime = String(mimeType ?? '').toLowerCase()
  const ext = normalizeExt(extension)

  const isText = mime.startsWith('text/') || TEXT_EXTS.has(ext)
  const isMultimodal =
    mime === 'application/pdf' ||
    mime.startsWith('image/') ||
    MULTIMODAL_EXTS.has(ext)

  return {
    summarize: isText || isMultimodal,
    analyze: isText || isMultimodal,
    ocr: isMultimodal,
    any: isText || isMultimodal,
  }
}
