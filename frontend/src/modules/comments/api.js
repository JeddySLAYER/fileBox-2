import api from '@/lib/api'

export const commentsApi = {
  listForDocument: (documentId) =>
    api.get(`/documents/${documentId}/comments`).then((r) => r.data),
  create: (documentId, payload) =>
    api.post(`/documents/${documentId}/comments`, payload).then((r) => r.data),
  update: (commentId, content) =>
    api.put(`/comments/${commentId}`, { content }).then((r) => r.data),
  remove: (commentId) => api.delete(`/comments/${commentId}`).then((r) => r.data),
}
