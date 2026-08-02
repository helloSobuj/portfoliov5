import { useEffect, useState } from 'react'
import { Link, Navigate, useParams } from 'react-router-dom'
import { ArrowLeft, ExternalLink } from 'lucide-react'
import { Section } from '@/components/ui/Section'
import { GithubIcon } from '@/components/icons/BrandIcons'
import { getPortfolioItemBySlug } from '@/lib/data'
import type { PortfolioItem } from '@/types'

export default function PortfolioDetailPage() {
  const { slug } = useParams<{ slug: string }>()
  const [item, setItem] = useState<PortfolioItem | null | undefined>(undefined)

  useEffect(() => {
    if (!slug) return
    setItem(undefined)
    getPortfolioItemBySlug(slug).then((data) => setItem(data ?? null))
  }, [slug])

  if (item === null) return <Navigate to="/portfolio" replace />

  return (
    <Section compact className="pt-16">
      <Link to="/portfolio" className="inline-flex items-center gap-2 text-sm text-fg-muted hover:text-fg">
        <ArrowLeft size={16} aria-hidden />
        Back to portfolio
      </Link>

      {item && (
        <article className="mt-8">
          <h1 className="text-3xl font-semibold md:text-4xl">{item.title}</h1>
          <p className="mt-3 max-w-2xl text-fg-muted">{item.description}</p>

          <div className="mt-6 flex flex-wrap gap-4">
            {item.liveUrl && (
              <a
                href={item.liveUrl}
                target="_blank"
                rel="noreferrer"
                className="inline-flex items-center gap-2 text-sm text-accent hover:opacity-80"
              >
                Live site <ExternalLink size={14} aria-hidden />
              </a>
            )}
            {item.repoUrl && (
              <a
                href={item.repoUrl}
                target="_blank"
                rel="noreferrer"
                className="inline-flex items-center gap-2 text-sm text-accent hover:opacity-80"
              >
                Source <GithubIcon width={14} height={14} aria-hidden />
              </a>
            )}
          </div>

          <div className="mt-10 aspect-video overflow-hidden rounded-lg bg-bg-elevated">
            <img src={item.coverImageUrl} alt="" className="h-full w-full object-cover" />
          </div>

          <ul className="mt-6 flex flex-wrap gap-2">
            {item.tags.map((tag) => (
              <li key={tag} className="rounded-full border border-border px-3 py-1 text-xs text-fg-muted">
                {tag}
              </li>
            ))}
          </ul>
        </article>
      )}
    </Section>
  )
}
