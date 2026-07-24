import api from '@/lib/api'

export const documentTypesApi = {
  list: (params = {}) => api.get('/document-types', { params }).then((r) => r.data),
  create: (payload) => api.post('/document-types', payload).then((r) => r.data),
  update: (id, payload) => api.put(`/document-types/${id}`, payload).then((r) => r.data),
  remove: (id) => api.delete(`/document-types/${id}`).then((r) => r.data),
  restore: (id) => api.post(`/document-types/${id}/restore`).then((r) => r.data),
}
