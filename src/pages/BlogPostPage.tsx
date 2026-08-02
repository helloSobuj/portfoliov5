import { useEffect, useState } from 'react'
import { Link, Navigate, useParams } from 'react-router-dom'
import ReactMarkdown from 'react-markdown'
import remarkGfm from 'remark-gfm'
import { ArrowLeft } from 'lucide-react'
import { Section } from '@/components/ui/Section'
import { getBlogPostBySlug } from '@/lib/data'
import type { BlogPost } from '@/types'

export default function BlogPostPage() {
  const { slug } = useParams<{ slug: string }>()
  const [post, setPost] = useState<BlogPost | null | undefined>(undefined)

  useEffect(() => {
    if (!slug) return
    setPost(undefined)
    getBlogPostBySlug(slug).then((data) => setPost(data ?? null))
  }, [slug])

  if (post === null) return <Navigate to="/blog" replace />

  return (
    <Section compact className="pt-16">
      <Link to="/blog" className="inline-flex items-center gap-2 text-sm text-fg-muted hover:text-fg">
        <ArrowLeft size={16} aria-hidden />
        Back to blog
      </Link>

      {post && (
        <article className="mx-auto mt-8 max-w-2xl">
          {post.publishedAt && (
            <time dateTime={post.publishedAt} className="text-xs text-fg-muted">
              {new Date(post.publishedAt).toLocaleDateString(undefined, {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
              })}
            </time>
          )}
          <h1 className="mt-2 text-3xl font-semibold md:text-4xl">{post.title}</h1>

          <div className="prose dark:prose-invert mt-8 max-w-none">
            <ReactMarkdown remarkPlugins={[remarkGfm]}>{post.contentMarkdown}</ReactMarkdown>
          </div>
        </article>
      )}
    </Section>
  )
}
