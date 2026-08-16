import api from '@/lib/api'

export const trashApi = {
  empty: () => api.post('/trash/empty').then((r) => r.data),
}
