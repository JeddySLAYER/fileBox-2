import { NavLink } from 'react-router-dom'
import {
  Activity,
  Archive,
  ArchiveRestore,
  Building2,
  ClipboardList,
  FileType,
  FolderKanban,
  FolderOpen,
  GitBranch,
  LayoutDashboard,
  LogOut,
  Settings,
  Share2,
  Shield,
  Sparkles,
  Star,
  Tags,
  Trash2,
  Users,
} from 'lucide-react'
import BrandMark from '@/components/BrandMark'
import { cn } from '@/lib/cn'
import { can, canAny } from '@/lib/permissions'
import { useAuthStore } from '@/stores/authStore'
import logo from '../../assets/images/LogoEBA.png'

const NAV = [
  {
    label: 'Pilotage',
    items: [
      { to: '/dashboard', label: 'Accueil', icon: LayoutDashboard, always: true },
      { to: '/explorer', label: 'Explorateur', icon: FolderOpen, always: true },
      { to: '/ia', label: 'Outils IA', icon: Sparkles, always: true },
      { to: '/favorites', label: 'Favoris', icon: Star, always: true },
    ],
  },
  {
    label: 'Organisation',
    items: [
      { to: '/users', label: 'Utilisateurs', icon: Users, permission: 'users.view' },
      { to: '/roles', label: 'Rôles', icon: Shield, permission: 'roles.manage' },
      { to: '/departments', label: 'Départements', icon: Building2, permission: 'departments.manage' },
      { to: '/projects', label: 'Projets', icon: FolderKanban, anyOf: ['projects.view', 'projects.manage'] },
    ],
  },
  {
    label: 'Processus',
    items: [
      { to: '/workflows', label: 'Workflows', icon: GitBranch, permission: 'workflows.manage' },
      {
        to: '/validations',
        label: 'Validations',
        icon: ClipboardList,
        always: true,
      },
      { to: '/document-types', label: 'Types docs', icon: FileType, permission: 'document_types.manage' },
      { to: '/tags', label: 'Tags', icon: Tags, permission: 'tags.manage' },
    ],
  },
  {
    label: 'Système',
    items: [
      { to: '/shared', label: 'Mes partages', icon: Share2, always: true },
      { to: '/archives', label: 'Archives', icon: ArchiveRestore, always: true },
      { to: '/trash', label: 'Corbeille', icon: Trash2, always: true },
      { to: '/settings', label: 'Paramètres', icon: Settings, permission: 'settings.manage' },
      { to: '/backups', label: 'Sauvegardes', icon: Archive, permission: 'settings.manage' },
      {
        to: '/activity',
        label: 'Journal',
        icon: Activity,
        anyOf: ['settings.manage', 'activity.view'],
      },
    ],
  },
]

function isVisible(user, item) {
  if (item.always) return true
  if (item.permission) return can(user, item.permission)
  if (item.anyOf) return canAny(user, item.anyOf)
  return false
}

export default function Sidebar({ onNavigate, onLogout }) {
  const user = useAuthStore((s) => s.user)

  return (
    <aside className="flex h-full w-64 flex-col border-r border-sidebar-border bg-sidebar text-sidebar-foreground">
      <div className="flex h-40 items-center gap-3 border-b border-sidebar-border px-5">
        <img src={logo} alt="Logo e-BA" />
      </div>

      <nav className="flex-1 overflow-y-auto px-3 py-4">
        {NAV.map((group) => {
          const items = group.items.filter((item) => isVisible(user, item))
          if (items.length === 0) return null

          return (
            <div key={group.label} className="mb-5">
              <p className="mb-2 px-3 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                {group.label}
              </p>
              <ul className="space-y-0.5">
                {items.map((item) => {
                  const Icon = item.icon
                  return (
                    <li key={item.to}>
                      <NavLink
                        to={item.to}
                        onClick={onNavigate}
                        className={({ isActive }) =>
                          cn(
                            'flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm transition-colors',
                            isActive
                              ? 'bg-accent text-accent-foreground font-medium'
                              : 'text-sidebar-foreground hover:bg-sidebar-accent',
                          )
                        }
                      >
                        <Icon className="h-4 w-4 shrink-0" strokeWidth={1.75} />
                        {item.label}
                      </NavLink>
                    </li>
                  )
                })}
              </ul>
            </div>
          )
        })}
      </nav>

      <div className="border-t border-sidebar-border p-3">
        <div className="mb-2 truncate px-3 text-xs">
          <p className="font-medium text-foreground">{user?.name}</p>
          <p className="truncate text-muted-foreground">{user?.email}</p>
        </div>
        <button
          type="button"
          onClick={onLogout}
          className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
        >
          <LogOut className="h-3.5 w-3.5" />
          Déconnexion
        </button>
      </div>
    </aside>
  )
}
