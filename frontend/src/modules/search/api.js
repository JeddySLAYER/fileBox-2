import api from '@/lib/api'

export const searchApi = {
  search: (params = {}) => api.get('/search', { params }).then((r) => r.data),
}
