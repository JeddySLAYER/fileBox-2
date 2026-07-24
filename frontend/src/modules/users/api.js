import api from '@/lib/api'

export const usersApi = {
  list: (params = {}) => api.get('/users', { params }).then((r) => r.data),
  get: (id) => api.get(`/users/${id}`).then((r) => r.data.user),
  create: (payload) => api.post('/users', payload).then((r) => r.data),
  update: (id, payload) => api.put(`/users/${id}`, payload).then((r) => r.data),
  remove: (id) => api.delete(`/users/${id}`).then((r) => r.data),
  resetPassword: (id) => api.post(`/users/${id}/reset-password`).then((r) => r.data),
}

