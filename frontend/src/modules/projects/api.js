import api from '@/lib/api'

export const projectsApi = {
  list: (params = {}) => api.get('/projects', { params }).then((r) => r.data),
  get: (id) => api.get(`/projects/${id}`).then((r) => r.data.project),
  create: (payload) => api.post('/projects', payload).then((r) => r.data),
  update: (id, payload) => api.put(`/projects/${id}`, payload).then((r) => r.data),
  remove: (id) => api.delete(`/projects/${id}`).then((r) => r.data),
  syncMembers: (id, memberIds) =>
    api.put(`/projects/${id}/members`, { member_ids: memberIds }).then((r) => r.data),
  memberCandidates: (id) =>
    api.get(`/projects/${id}/member-candidates`).then((r) => r.data.data ?? r.data),
}
