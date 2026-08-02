import { useEffect, useState } from 'react'
import { Section } from '@/components/ui/Section'
import { PortfolioGrid } from '@/components/portfolio/PortfolioGrid'
import { getPortfolioItems } from '@/lib/data'
import type { PortfolioItem } from '@/types'

export default function PortfolioPage() {
  const [items, setItems] = useState<PortfolioItem[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    getPortfolioItems().then((data) => {
      setItems(data)
      setLoading(false)
    })
  }, [])

  return (
    <Section compact className="pt-16">
      <h1 className="text-3xl font-semibold md:text-4xl">Portfolio</h1>
      <p className="mt-3 max-w-xl text-fg-muted">
        A selection of projects across web, mobile, and design.
      </p>
      <div className="mt-10">
        {loading ? <p className="text-fg-muted">Loading…</p> : <PortfolioGrid items={items} />}
      </div>
    </Section>
  )
}
