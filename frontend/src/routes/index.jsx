import { Navigate, Route, Routes } from 'react-router-dom'
import { RequirePermissionRoute } from '@/components/RequirePermission'
import AppShell from '@/layouts/AppShell'
import MyAccessesPage from '@/modules/access/pages/MyAccessesPage'
import ActivityPage from '@/modules/activity/pages/ActivityPage'
import { GuestOnly, RequireAuth } from '@/modules/auth/guards'
import ChangePasswordPage from '@/modules/auth/pages/ChangePasswordPage'
import LoginPage from '@/modules/auth/pages/LoginPage'
import BackupsPage from '@/modules/backups/pages/BackupsPage'
import DashboardPage from '@/modules/dashboard/pages/DashboardPage'
import DepartmentsPage from '@/modules/departments/pages/DepartmentsPage'
import DocumentTypesPage from '@/modules/document-types/pages/DocumentTypesPage'
import DocumentDetailPage from '@/modules/documents/pages/DocumentDetailPage'
import ArchivesPage from '@/modules/documents/pages/ArchivesPage'
import ExplorerPage from '@/modules/folders/pages/ExplorerPage'
import FavoritesPage from '@/modules/favorites/pages/FavoritesPage'
import AiToolsPage from '@/modules/ai/pages/AiToolsPage'
import NotificationsPage from '@/modules/notifications/pages/NotificationsPage'
import ProjectDetailPage from '@/modules/projects/pages/ProjectDetailPage'
import ProjectsPage from '@/modules/projects/pages/ProjectsPage'
import RolesPage from '@/modules/roles/pages/RolesPage'
import SettingsPage from '@/modules/settings/pages/SettingsPage'
import TagsPage from '@/modules/tags/pages/TagsPage'
import TrashPage from '@/modules/trash/pages/TrashPage'
import UsersPage from '@/modules/users/pages/UsersPage'
import WorkflowDetailPage from '@/modules/workflows/pages/WorkflowDetailPage'
import WorkflowsPage from '@/modules/workflows/pages/WorkflowsPage'
import ProposedDocumentsPage from '@/modules/validations/pages/ProposedDocumentsPage'

export default function AppRoutes() {
  return (
    <Routes>
      <Route element={<GuestOnly />}>
        <Route path="/login" element={<LoginPage />} />
      </Route>

      <Route element={<RequireAuth />}>
        <Route path="/change-password" element={<ChangePasswordPage />} />

        <Route element={<AppShell />}>
          <Route index element={<Navigate to="/dashboard" replace />} />
          <Route path="/dashboard" element={<DashboardPage />} />
          <Route path="/explorer" element={<ExplorerPage />} />
          <Route path="/ia" element={<AiToolsPage />} />
          <Route path="/favorites" element={<FavoritesPage />} />
          <Route path="/documents/:id" element={<DocumentDetailPage />} />
          <Route path="/search" element={<Navigate to="/explorer" replace />} />
          <Route path="/notifications" element={<NotificationsPage />} />
          <Route path="/shared" element={<MyAccessesPage />} />
          <Route path="/archives" element={<ArchivesPage />} />
          <Route path="/trash" element={<TrashPage />} />

          <Route
            path="/users"
            element={
              <RequirePermissionRoute permission="users.view">
                <UsersPage />
              </RequirePermissionRoute>
            }
          />
          <Route
            path="/roles"
            element={
              <RequirePermissionRoute permission="roles.manage">
                <RolesPage />
              </RequirePermissionRoute>
            }
          />
          <Route
            path="/departments"
            element={
              <RequirePermissionRoute permission="departments.manage">
                <DepartmentsPage />
              </RequirePermissionRoute>
            }
          />
          <Route
            path="/projects"
            element={
              <RequirePermissionRoute anyOf={['projects.view', 'projects.manage']}>
                <ProjectsPage />
              </RequirePermissionRoute>
            }
          />
          <Route
            path="/projects/:id"
            element={
              <RequirePermissionRoute anyOf={['projects.view', 'projects.manage']}>
                <ProjectDetailPage />
              </RequirePermissionRoute>
            }
          />
          <Route
            path="/workflows"
            element={
              <RequirePermissionRoute permission="workflows.manage">
                <WorkflowsPage />
              </RequirePermissionRoute>
            }
          />
          <Route
            path="/workflows/:id"
            element={
              <RequirePermissionRoute permission="workflows.manage">
                <WorkflowDetailPage />
              </RequirePermissionRoute>
            }
          />
          <Route path="/validations" element={<ProposedDocumentsPage />} />
          <Route path="/proposed" element={<Navigate to="/validations?tab=propositions" replace />} />
          <Route
            path="/document-types"
            element={
              <RequirePermissionRoute permission="document_types.manage">
                <DocumentTypesPage />
              </RequirePermissionRoute>
            }
          />
          <Route
            path="/tags"
            element={
              <RequirePermissionRoute permission="tags.manage">
                <TagsPage />
              </RequirePermissionRoute>
            }
          />
          <Route
            path="/settings"
            element={
              <RequirePermissionRoute permission="settings.manage">
                <SettingsPage />
              </RequirePermissionRoute>
            }
          />
          <Route
            path="/backups"
            element={
              <RequirePermissionRoute permission="settings.manage">
                <BackupsPage />
              </RequirePermissionRoute>
            }
          />
          <Route
            path="/activity"
            element={
              <RequirePermissionRoute anyOf={['settings.manage', 'activity.view']}>
                <ActivityPage />
              </RequirePermissionRoute>
            }
          />
        </Route>
      </Route>

      <Route path="*" element={<Navigate to="/dashboard" replace />} />
    </Routes>
  )
}
