import api from '@/lib/api'

export const ACCESS_ABILITIES = ['view', 'download', 'edit', 'delete', 'share', 'manage']

export const accessesApi = {
  mine: (params = {}) => api.get('/accesses/mine', { params }).then((r) => r.data),
  listForDocument: (documentId) =>
    api.get(`/documents/${documentId}/accesses`).then((r) => r.data),
  grantDocument: (documentId, payload) =>
    api.post(`/documents/${documentId}/accesses`, payload).then((r) => r.data),
  listForFolder: (folderId) =>
    api.get(`/folders/${folderId}/accesses`).then((r) => r.data),
  grantFolder: (folderId, payload) =>
    api.post(`/folders/${folderId}/accesses`, payload).then((r) => r.data),
  update: (accessId, payload) =>
    api.put(`/accesses/${accessId}`, payload).then((r) => r.data),
  revoke: (accessId) => api.delete(`/accesses/${accessId}`).then((r) => r.data),
}
