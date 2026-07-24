import api from '@/lib/api'

export const activityLogsApi = {
  list: (params = {}) => api.get('/activity-logs', { params }).then((r) => r.data),
}
