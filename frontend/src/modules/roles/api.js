import api from '@/lib/api'

export const rolesApi = {
  list: () => api.get('/roles').then((r) => r.data),
  get: (id) => api.get(`/roles/${id}`).then((r) => r.data.role),
  create: (payload) => api.post('/roles', payload).then((r) => r.data),
  update: (id, payload) => api.put(`/roles/${id}`, payload).then((r) => r.data),
  remove: (id) => api.delete(`/roles/${id}`).then((r) => r.data),
  syncPermissions: (id, permissionIds) =>
    api.put(`/roles/${id}/permissions`, { permission_ids: permissionIds }).then((r) => r.data),
}

export const permissionsApi = {
  list: (params = {}) => api.get('/permissions', { params }).then((r) => r.data),
}
