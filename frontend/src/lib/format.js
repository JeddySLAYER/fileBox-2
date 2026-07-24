import dayjs from 'dayjs'
import 'dayjs/locale/fr'

dayjs.locale('fr')

export function formatDate(value, withTime = false) {
  if (!value) return '—'
  return dayjs(value).format(withTime ? 'DD/MM/YYYY HH:mm' : 'DD/MM/YYYY')
}

export function formatBytes(bytes) {
  if (bytes == null || Number.isNaN(Number(bytes))) return '—'
  const n = Number(bytes)
  if (n < 1024) return `${n} o`
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} Ko`
  return `${(n / (1024 * 1024)).toFixed(1)} Mo`
}

export const documentStatusLabels = {
  brouillon: 'Brouillon',
  en_validation: 'En validation',
  valide: 'Validé',
  publie: 'Publié',
  rejete: 'Rejeté',
  archive: 'Archivé',
  supprime: 'Supprimé',
}

export const validationStatusLabels = {
  en_attente: 'En attente',
  approuve: 'Approuvé',
  rejete: 'Rejeté',
  correction_demandee: 'Correction demandée',
}

export function validationStatusLabel(status) {
  return validationStatusLabels[status] ?? status ?? '—'
}

export function statusLabel(status) {
  return documentStatusLabels[status] ?? status ?? '—'
}
