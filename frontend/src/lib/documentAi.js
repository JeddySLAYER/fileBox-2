/** Extensions / MIME pris en charge par Gemini côté FileBox. */
export const TEXT_EXTENSIONS = [
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
]
const TEXT_EXTS = new Set(TEXT_EXTENSIONS)

export const OCR_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp']
export const ENHANCE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp']
export const BRIEF_EXTENSIONS = [...new Set([...TEXT_EXTENSIONS, ...OCR_EXTENSIONS])]
export const AI_FILE_ACCEPT = [
  ...BRIEF_EXTENSIONS.map((ext) => `.${ext}`),
  'application/pdf',
  'image/jpeg',
  'image/png',
  'image/gif',
  'image/webp',
  'image/bmp',
  'text/plain',
].join(',')

const MULTIMODAL_EXTS = new Set(OCR_EXTENSIONS)
const IMAGE_EXTS = new Set(ENHANCE_EXTENSIONS)

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
  const isImage =
    (mime.startsWith('image/') && mime !== 'image/svg+xml') || IMAGE_EXTS.has(ext)
  const isMultimodal =
    mime === 'application/pdf' || isImage || MULTIMODAL_EXTS.has(ext)

  return {
    brief: isText || isMultimodal,
    ocr: isMultimodal,
    enhance: isImage,
    any: isText || isMultimodal,
  }
}

export function fileFromBase64(base64, fileName, mimeType) {
  const binary = atob(base64)
  const bytes = new Uint8Array(binary.length)
  for (let i = 0; i < binary.length; i += 1) {
    bytes[i] = binary.charCodeAt(i)
  }
  return new File([bytes], fileName || 'image.png', {
    type: mimeType || 'image/png',
  })
}

export function textFileFromOcr(text, originalName) {
  const stem = String(originalName || 'document').replace(/\.[^.]+$/, '') || 'document'
  return new File([text], `${stem}-ocr.txt`, { type: 'text/plain' })
}

export function textFileFromBrief(text, originalName) {
  const stem = String(originalName || 'document').replace(/\.[^.]+$/, '') || 'document'
  return new File([text], `${stem}-analyse.txt`, { type: 'text/plain' })
}

export function downloadBlob(blob, fileName) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = fileName
  a.click()
  URL.revokeObjectURL(url)
}
