import {
  File,
  FileArchive,
  FileAudio,
  FileCode,
  FileImage,
  FileSpreadsheet,
  FileText,
  FileVideo,
  Folder,
} from 'lucide-react'

/** Couleurs / icônes type explorateur Windows, par extension. */
const EXT = {
  pdf: { color: '#E53935', Icon: FileText, label: 'PDF' },
  doc: { color: '#1E88E5', Icon: FileText, label: 'Word' },
  docx: { color: '#1E88E5', Icon: FileText, label: 'Word' },
  odt: { color: '#1E88E5', Icon: FileText, label: 'Texte' },
  rtf: { color: '#1E88E5', Icon: FileText, label: 'Texte' },
  txt: { color: '#546E7A', Icon: FileText, label: 'Texte' },
  md: { color: '#546E7A', Icon: FileText, label: 'Markdown' },
  csv: { color: '#43A047', Icon: FileSpreadsheet, label: 'CSV' },
  xls: { color: '#43A047', Icon: FileSpreadsheet, label: 'Excel' },
  xlsx: { color: '#43A047', Icon: FileSpreadsheet, label: 'Excel' },
  ods: { color: '#43A047', Icon: FileSpreadsheet, label: 'Tableur' },
  ppt: { color: '#FB8C00', Icon: FileText, label: 'PowerPoint' },
  pptx: { color: '#FB8C00', Icon: FileText, label: 'PowerPoint' },
  png: { color: '#8E24AA', Icon: FileImage, label: 'Image' },
  jpg: { color: '#8E24AA', Icon: FileImage, label: 'Image' },
  jpeg: { color: '#8E24AA', Icon: FileImage, label: 'Image' },
  gif: { color: '#8E24AA', Icon: FileImage, label: 'Image' },
  webp: { color: '#8E24AA', Icon: FileImage, label: 'Image' },
  svg: { color: '#8E24AA', Icon: FileImage, label: 'Image' },
  mp3: { color: '#00ACC1', Icon: FileAudio, label: 'Audio' },
  wav: { color: '#00ACC1', Icon: FileAudio, label: 'Audio' },
  mp4: { color: '#5E35B1', Icon: FileVideo, label: 'Vidéo' },
  webm: { color: '#5E35B1', Icon: FileVideo, label: 'Vidéo' },
  zip: { color: '#6D4C41', Icon: FileArchive, label: 'Archive' },
  rar: { color: '#6D4C41', Icon: FileArchive, label: 'Archive' },
  '7z': { color: '#6D4C41', Icon: FileArchive, label: 'Archive' },
  json: { color: '#F9A825', Icon: FileCode, label: 'JSON' },
  xml: { color: '#F9A825', Icon: FileCode, label: 'XML' },
  html: { color: '#F9A825', Icon: FileCode, label: 'HTML' },
  js: { color: '#F9A825', Icon: FileCode, label: 'Code' },
  ts: { color: '#F9A825', Icon: FileCode, label: 'Code' },
  css: { color: '#F9A825', Icon: FileCode, label: 'Code' },
}

export function extensionOf(doc) {
  const fromVersion = doc?.current_version?.extension || doc?.extension
  if (fromVersion) return String(fromVersion).replace(/^\./, '').toLowerCase()
  const name = doc?.current_version?.file_name || doc?.file_name || doc?.title || ''
  const m = String(name).match(/\.([a-z0-9]+)$/i)
  return m ? m[1].toLowerCase() : ''
}

export function fileVisual(doc) {
  const ext = extensionOf(doc)
  const meta = EXT[ext] || { color: '#78909C', Icon: File, label: ext ? ext.toUpperCase() : 'Fichier' }
  return { ...meta, ext }
}

export function folderVisual() {
  return { color: '#F4B400', Icon: Folder, label: 'Dossier', ext: '' }
}
