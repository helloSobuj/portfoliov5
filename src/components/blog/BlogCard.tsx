import { Link } from 'react-router-dom'
import { motion } from 'framer-motion'
import type { BlogPost } from '@/types'

export function BlogCard({ post }: { post: BlogPost }) {
  return (
    <motion.article initial={{ opacity: 0, y: 12 }} whileInView={{ opacity: 1, y: 0 }} viewport={{ once: true }}>
      <Link to={`/blog/${post.slug}`} className="group grid gap-6 sm:grid-cols-[220px_1fr]">
        {post.coverImageUrl && (
          <div className="aspect-4/3 overflow-hidden rounded-lg bg-bg-elevated sm:aspect-square">
            <img
              src={post.coverImageUrl}
              alt=""
              loading="lazy"
              className="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105"
            />
          </div>
        )}
        <div>
          {post.publishedAt && (
            <time dateTime={post.publishedAt} className="text-xs text-fg-muted">
              {new Date(post.publishedAt).toLocaleDateString(undefined, {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
              })}
            </time>
          )}
          <h2 className="mt-2 text-xl font-medium">{post.title}</h2>
          <p className="mt-2 text-fg-muted">{post.excerpt}</p>
        </div>
      </Link>
    </motion.article>
  )
}
