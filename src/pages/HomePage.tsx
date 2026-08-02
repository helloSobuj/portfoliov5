import { useEffect, useState } from 'react'
import { Hero } from '@/components/home/Hero'
import { QuickStats } from '@/components/home/QuickStats'
import { AboutTeaser } from '@/components/home/AboutTeaser'
import { PortfolioGalleryTabs } from '@/components/home/PortfolioGalleryTabs'
import { Services } from '@/components/home/Services'
import { CTA } from '@/components/home/CTA'
import { getPortfolioItems } from '@/lib/data'
import type { PortfolioItem } from '@/types'

export default function HomePage() {
  const [items, setItems] = useState<PortfolioItem[]>([])

  useEffect(() => {
    let active = true
    getPortfolioItems().then((data) => {
      if (active) setItems(data)
    })
    return () => {
      active = false
    }
  }, [])

  return (
    <>
      <Hero />
      <QuickStats />
      <AboutTeaser />
      <PortfolioGalleryTabs items={items} />
      <Services />
      <CTA />
    </>
  )
}
