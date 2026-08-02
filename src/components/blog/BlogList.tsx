import { BlogCard } from './BlogCard'
import type { BlogPost } from '@/types'

export function BlogList({ posts }: { posts: BlogPost[] }) {
  if (posts.length === 0) {
    return <p className="text-fg-muted">No posts yet — check back soon.</p>
  }

  return (
    <div className="flex flex-col gap-12">
      {posts.map((post) => (
        <BlogCard key={post.id} post={post} />
      ))}
    </div>
  )
}
