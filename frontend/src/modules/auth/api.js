import api from '@/lib/api'

export const authApi = {
  login: (payload) => api.post('/auth/login', payload).then((r) => r.data),
  me: () => api.get('/auth/me').then((r) => r.data),
  logout: () => api.post('/auth/logout').then((r) => r.data),
  changePassword: (payload) => api.put('/auth/password', payload).then((r) => r.data),
}
