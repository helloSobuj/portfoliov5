import { Link } from 'react-router-dom'
import { Briefcase, FileText, Files } from 'lucide-react'
import { useAuth } from '@/context/AuthContext'

const cards = [
  { to: '/admin/portfolio', label: 'Portfolio items', icon: Briefcase, description: 'Create and edit case studies.' },
  { to: '/admin/blog', label: 'Blog posts', icon: FileText, description: 'Write and publish posts.' },
  { to: '/admin/pages', label: 'Custom pages', icon: Files, description: 'Markdown & HTML page builder.' },
]

export default function AdminDashboardPage() {
  const { profile } = useAuth()

  return (
    <div>
      <h1 className="text-2xl font-semibold">Welcome{profile ? `, ${profile.displayName || profile.email}` : ''}</h1>
      <p className="mt-2 text-fg-muted">Manage your portfolio content from here.</p>

      <div className="mt-10 grid gap-6 sm:grid-cols-3">
        {cards.map(({ to, label, icon: Icon, description }) => (
          <Link key={to} to={to} className="rounded-lg border border-border p-6 hover:bg-bg-elevated">
            <Icon size={20} className="text-accent" aria-hidden />
            <h2 className="mt-4 font-medium">{label}</h2>
            <p className="mt-1 text-sm text-fg-muted">{description}</p>
          </Link>
        ))}
      </div>
    </div>
  )
}
