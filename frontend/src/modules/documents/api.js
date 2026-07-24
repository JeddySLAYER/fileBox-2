import api from '@/lib/api'

export const documentsApi = {
  list: (params = {}) => api.get('/documents', { params }).then((r) => r.data),
  get: (id) => api.get(`/documents/${id}`).then((r) => r.data.document),
  create: (formData) =>
    api
      .post('/documents', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      .then((r) => r.data),
  update: (id, payload) => api.put(`/documents/${id}`, payload).then((r) => r.data),
  remove: (id) => api.delete(`/documents/${id}`).then((r) => r.data),
  restore: (id) => api.post(`/documents/${id}/restore`).then((r) => r.data),
  archive: (id) => api.post(`/documents/${id}/archive`).then((r) => r.data),
  unarchive: (id) => api.post(`/documents/${id}/unarchive`).then((r) => r.data),
  versions: (id) => api.get(`/documents/${id}/versions`).then((r) => r.data),
  storeVersion: (id, formData) =>
    api
      .post(`/documents/${id}/versions`, formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      .then((r) => r.data),
  content: (id) => api.get(`/documents/${id}/content`).then((r) => r.data),
  saveContent: (id, payload) => api.put(`/documents/${id}/content`, payload).then((r) => r.data),
  requestSignedPreview: (id, expiresMinutes = 15) =>
    api
      .get(`/documents/${id}/preview-url`, { params: { expires_minutes: expiresMinutes } })
      .then((r) => r.data),
}

export async function downloadDocument(id, fileName = 'document') {
  const response = await api.get(`/documents/${id}/download`, { responseType: 'blob' })
  const url = window.URL.createObjectURL(response.data)
  const a = document.createElement('a')
  a.href = url
  a.download = fileName
  a.click()
  window.URL.revokeObjectURL(url)
}
