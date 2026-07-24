import api from '@/lib/api'

export const notificationsApi = {
  list: (params = {}) => api.get('/notifications', { params }).then((r) => r.data),
  unreadCount: () => api.get('/notifications/unread-count').then((r) => r.data.unread_count),
  markAsRead: (id) => api.post(`/notifications/${id}/read`).then((r) => r.data),
  markAllAsRead: () => api.post('/notifications/read-all').then((r) => r.data),
}
