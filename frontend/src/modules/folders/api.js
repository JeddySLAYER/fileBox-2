import api from '@/lib/api'

export const foldersApi = {
  list: (params = {}) => api.get('/folders', { params }).then((r) => r.data),
  tree: (params = {}) => api.get('/folders/tree', { params }).then((r) => r.data),
  get: (id) => api.get(`/folders/${id}`).then((r) => r.data.folder),
  create: (payload) => api.post('/folders', payload).then((r) => r.data),
  update: (id, payload) => api.put(`/folders/${id}`, payload).then((r) => r.data),
  move: (id, parentId) =>
    api.put(`/folders/${id}/move`, { parent_id: parentId }).then((r) => r.data),
  remove: (id) => api.delete(`/folders/${id}`).then((r) => r.data),
  restore: (id) => api.post(`/folders/${id}/restore`).then((r) => r.data),
}
