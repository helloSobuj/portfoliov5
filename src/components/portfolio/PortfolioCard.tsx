import { Link } from 'react-router-dom'
import { motion } from 'framer-motion'
import type { PortfolioItem } from '@/types'

export function PortfolioCard({ item }: { item: PortfolioItem }) {
  return (
    <motion.div layout initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0 }}>
      <Link to={`/portfolio/${item.slug}`} className="group block">
        <div className="aspect-4/3 overflow-hidden rounded-lg bg-bg-elevated">
          <img
            src={item.coverImageUrl}
            alt=""
            loading="lazy"
            className="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105"
          />
        </div>
        <div className="mt-4 flex items-start justify-between gap-4">
          <div>
            <h3 className="font-medium">{item.title}</h3>
            <p className="mt-1 text-sm text-fg-muted">{item.summary}</p>
          </div>
        </div>
        <ul className="mt-3 flex flex-wrap gap-2">
          {item.tags.map((tag) => (
            <li key={tag} className="rounded-full border border-border px-2.5 py-0.5 text-xs text-fg-muted">
              {tag}
            </li>
          ))}
        </ul>
      </Link>
    </motion.div>
  )
}
