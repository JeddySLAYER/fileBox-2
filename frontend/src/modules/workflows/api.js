import api from '@/lib/api'

export const workflowsApi = {
  list: (params = {}) => api.get('/workflows', { params }).then((r) => r.data),
  get: (id) => api.get(`/workflows/${id}`).then((r) => r.data.workflow),
  create: (payload) => api.post('/workflows', payload).then((r) => r.data),
  update: (id, payload) => api.put(`/workflows/${id}`, payload).then((r) => r.data),
  remove: (id) => api.delete(`/workflows/${id}`).then((r) => r.data),
  restore: (id) => api.post(`/workflows/${id}/restore`).then((r) => r.data),
}
