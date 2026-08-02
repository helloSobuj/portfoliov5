import { useEffect, useState } from 'react'
import { Section } from '@/components/ui/Section'
import { BlogList } from '@/components/blog/BlogList'
import { getBlogPosts } from '@/lib/data'
import type { BlogPost } from '@/types'

export default function BlogPage() {
  const [posts, setPosts] = useState<BlogPost[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    getBlogPosts().then((data) => {
      setPosts(data)
      setLoading(false)
    })
  }, [])

  return (
    <Section compact className="pt-16">
      <h1 className="text-3xl font-semibold md:text-4xl">Blog</h1>
      <p className="mt-3 max-w-xl text-fg-muted">Notes on building products, animation, and design systems.</p>
      <div className="mt-10">
        {loading ? <p className="text-fg-muted">Loading…</p> : <BlogList posts={posts} />}
      </div>
    </Section>
  )
}
