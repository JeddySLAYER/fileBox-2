import api from '@/lib/api'

export const departmentsApi = {
  list: (params = {}) => api.get('/departments', { params }).then((r) => r.data),
  get: (id) => api.get(`/departments/${id}`).then((r) => r.data.department),
  create: (payload) => api.post('/departments', payload).then((r) => r.data),
  update: (id, payload) => api.put(`/departments/${id}`, payload).then((r) => r.data),
  remove: (id) => api.delete(`/departments/${id}`).then((r) => r.data),
}
