import { QueryClient } from '@tanstack/react-query'

export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      retry: 1,
      refetchOnWindowFocus: false,
    },
  },
})

export const queryKeys = {
  me: ['auth', 'me'],
  dashboard: ['dashboard'],
  documents: (filters) => ['documents', filters],
  document: (id) => ['documents', id],
  documentVersions: (id) => ['documents', id, 'versions'],
  folders: (filters) => ['folders', filters],
  folderTree: (filters) => ['folders', 'tree', filters],
  search: (filters) => ['search', filters],
  favorites: ['favorites'],
  notifications: (filters) => ['notifications', filters],
  unreadNotifications: ['notifications', 'unread-count'],
  users: (filters) => ['users', filters],
  user: (id) => ['users', id],
  roles: ['roles'],
  role: (id) => ['roles', id],
  permissions: ['permissions'],
  departments: (filters) => ['departments', filters],
  projects: (filters) => ['projects', filters],
  workflows: (filters) => ['workflows', filters],
  workflow: (id) => ['workflows', id],
  documentValidations: (id) => ['documents', id, 'validations'],
  validationsInbox: ['validations', 'inbox'],
  documentComments: (id) => ['documents', id, 'comments'],
  documentAccesses: (id) => ['documents', id, 'accesses'],
  folderAccesses: (id) => ['folders', id, 'accesses'],
  documentTypes: (filters) => ['document-types', filters],
  tags: ['tags'],
  project: (id) => ['projects', id],
  myAccesses: (filters) => ['accesses', 'mine', filters],
  settings: ['settings'],
  backups: ['backups'],
  activityLogs: (filters) => ['activity-logs', filters],
}
