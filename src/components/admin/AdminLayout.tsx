import { NavLink, Outlet } from 'react-router-dom'
import { LayoutDashboard, Briefcase, FileText, Files, LogOut } from 'lucide-react'
import { useAuth } from '@/context/AuthContext'
import { ThemeToggle } from '@/components/ui/ThemeToggle'
import { cn } from '@/lib/cn'

const links = [
  { to: '/admin', label: 'Dashboard', icon: LayoutDashboard, end: true },
  { to: '/admin/portfolio', label: 'Portfolio', icon: Briefcase },
  { to: '/admin/blog', label: 'Blog', icon: FileText },
  { to: '/admin/pages', label: 'Pages', icon: Files },
]

export function AdminLayout() {
  const { profile, signOut } = useAuth()

  return (
    <div className="flex min-h-screen bg-bg text-fg">
      <aside className="hidden w-60 shrink-0 border-r border-border p-6 sm:flex sm:flex-col">
        <p className="font-heading font-semibold">Admin</p>
        {profile && <p className="mt-1 text-xs text-fg-muted">{profile.email}</p>}

        <nav className="mt-8 flex flex-col gap-1">
          {links.map(({ to, label, icon: Icon, end }) => (
            <NavLink
              key={to}
              to={to}
              end={end}
              className={({ isActive }) =>
                cn(
                  'flex items-center gap-2 rounded-md px-3 py-2 text-sm',
                  isActive ? 'bg-bg-elevated text-fg' : 'text-fg-muted hover:text-fg',
                )
              }
            >
              <Icon size={16} aria-hidden />
              {label}
            </NavLink>
          ))}
        </nav>

        <div className="mt-auto flex items-center justify-between pt-6">
          <ThemeToggle />
          <button
            type="button"
            onClick={() => signOut()}
            className="flex items-center gap-2 text-sm text-fg-muted hover:text-fg cursor-pointer"
          >
            <LogOut size={16} aria-hidden />
            Sign out
          </button>
        </div>
      </aside>

      <main className="flex-1 p-6 sm:p-10">
        <Outlet />
      </main>
    </div>
  )
}
