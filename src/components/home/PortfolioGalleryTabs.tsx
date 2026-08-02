import { useMemo, useState } from 'react'
import { AnimatePresence, motion } from 'framer-motion'
import { Link } from 'react-router-dom'
import { Section } from '@/components/ui/Section'
import { LinkButton } from '@/components/ui/Button'
import { cn } from '@/lib/cn'
import type { PortfolioCategory, PortfolioItem } from '@/types'

const TABS: { label: string; value: PortfolioCategory | 'all' }[] = [
  { label: 'All', value: 'all' },
  { label: 'Web', value: 'web' },
  { label: 'Mobile', value: 'mobile' },
  { label: 'Design', value: 'design' },
  { label: 'Writing', value: 'writing' },
]

export function PortfolioGalleryTabs({ items }: { items: PortfolioItem[] }) {
  const [tab, setTab] = useState<PortfolioCategory | 'all'>('all')

  const filtered = useMemo(
    () => (tab === 'all' ? items : items.filter((item) => item.category === tab)),
    [items, tab],
  )

  return (
    <Section className="border-t border-border">
      <div className="flex flex-wrap items-end justify-between gap-6">
        <h2 className="text-2xl font-semibold md:text-3xl">Selected work</h2>
        <div
          role="tablist"
          aria-label="Filter portfolio by category"
          className="flex flex-wrap gap-2"
        >
          {TABS.map((t) => (
            <button
              key={t.value}
              type="button"
              role="tab"
              aria-selected={tab === t.value}
              onClick={() => setTab(t.value)}
              className={cn(
                'rounded-full px-4 py-1.5 text-sm transition-colors cursor-pointer',
                tab === t.value
                  ? 'bg-accent text-accent-fg'
                  : 'border border-border text-fg-muted hover:text-fg',
              )}
            >
              {t.label}
            </button>
          ))}
        </div>
      </div>

      <div className="mt-10 grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
        <AnimatePresence mode="popLayout">
          {filtered.map((project) => (
            <motion.div
              key={project.id}
              layout
              initial={{ opacity: 0, y: 12 }}
              animate={{ opacity: 1, y: 0 }}
              exit={{ opacity: 0 }}
              transition={{ duration: 0.3 }}
            >
              <Link to={`/portfolio/${project.slug}`} className="group block">
                <div className="aspect-4/3 overflow-hidden rounded-lg bg-bg-elevated">
                  <img
                    src={project.coverImageUrl}
                    alt=""
                    loading="lazy"
                    className="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105"
                  />
                </div>
                <h3 className="mt-4 font-medium">{project.title}</h3>
                <p className="mt-1 text-sm text-fg-muted">{project.summary}</p>
              </Link>
            </motion.div>
          ))}
        </AnimatePresence>
      </div>

      <div className="mt-12 text-center">
        <LinkButton to="/portfolio" variant="secondary">
          View all work
        </LinkButton>
      </div>
    </Section>
  )
}
