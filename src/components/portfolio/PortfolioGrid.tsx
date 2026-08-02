import { AnimatePresence } from 'framer-motion'
import { PortfolioCard } from './PortfolioCard'
import type { PortfolioItem } from '@/types'

export function PortfolioGrid({ items }: { items: PortfolioItem[] }) {
  if (items.length === 0) {
    return <p className="text-fg-muted">No projects to show yet.</p>
  }

  return (
    <div className="grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
      <AnimatePresence mode="popLayout">
        {items.map((item) => (
          <PortfolioCard key={item.id} item={item} />
        ))}
      </AnimatePresence>
    </div>
  )
}
