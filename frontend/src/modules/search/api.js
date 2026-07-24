import api from '@/lib/api'

/**
 * include_ocr : déjà supporté côté API (sera utile quand l'OCR remplira versions.ocr_text).
 */
export const searchApi = {
  search: (params = {}) => api.get('/search', { params }).then((r) => r.data),
}
