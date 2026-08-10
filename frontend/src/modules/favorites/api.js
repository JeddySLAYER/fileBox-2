import api from '@/lib/api'

export const favoritesApi = {
  list: () => api.get('/favorites').then((r) => r.data),
  addDocument: (id) => api.post(`/documents/${id}/favorite`).then((r) => r.data),
  removeDocument: (id) => api.delete(`/documents/${id}/favorite`).then((r) => r.data),
  addFolder: (id) => api.post(`/folders/${id}/favorite`).then((r) => r.data),
  removeFolder: (id) => api.delete(`/folders/${id}/favorite`).then((r) => r.data),
}
