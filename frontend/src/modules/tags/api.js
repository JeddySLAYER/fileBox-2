import api from '@/lib/api'

export const tagsApi = {
  list: () => api.get('/tags').then((r) => r.data),
  create: (payload) => api.post('/tags', payload).then((r) => r.data),
  update: (id, payload) => api.put(`/tags/${id}`, payload).then((r) => r.data),
  remove: (id) => api.delete(`/tags/${id}`).then((r) => r.data),
  syncDocument: (documentId, tagIds) =>
    api.put(`/documents/${documentId}/tags`, { tag_ids: tagIds }).then((r) => r.data),
}
