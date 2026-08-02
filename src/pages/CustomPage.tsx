import { useEffect, useState } from 'react'
import { Navigate, useParams } from 'react-router-dom'
import ReactMarkdown from 'react-markdown'
import remarkGfm from 'remark-gfm'
import { Section } from '@/components/ui/Section'
import { getPageBySlug } from '@/lib/data'
import { sanitizeHtml } from '@/lib/sanitize'
import type { PageContent } from '@/types'

export default function CustomPage() {
  const { slug } = useParams<{ slug: string }>()
  const [page, setPage] = useState<PageContent | null | undefined>(undefined)

  useEffect(() => {
    if (!slug) return
    setPage(undefined)
    getPageBySlug(slug).then((data) => setPage(data ?? null))
  }, [slug])

  if (page === null) return <Navigate to="/" replace />

  return (
    <Section compact className="pt-16">
      {page && (
        <article className="mx-auto max-w-2xl">
          <h1 className="text-3xl font-semibold md:text-4xl">{page.title}</h1>
          <div className="prose dark:prose-invert mt-8 max-w-none">
            {page.format === 'markdown' ? (
              <ReactMarkdown remarkPlugins={[remarkGfm]}>{page.body}</ReactMarkdown>
            ) : (
              // Sanitized with DOMPurify — see src/lib/sanitize.ts.
              // eslint-disable-next-line react/no-danger
              <div dangerouslySetInnerHTML={{ __html: sanitizeHtml(page.body) }} />
            )}
          </div>
        </article>
      )}
    </Section>
  )
}
