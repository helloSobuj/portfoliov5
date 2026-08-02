import { supabase } from '@/lib/supabase'
import { blogPosts, pages, portfolioItems } from '@/data/mock'
import { mapBlogPost, mapPageContent, mapPortfolioItem } from '@/lib/mappers'
import type { BlogPost, PageContent, PortfolioItem } from '@/types'

/**
 * Thin data-access layer. Every function tries Supabase first and falls
 * back to the bundled mock content, so the site is fully browsable before
 * a Supabase project is wired up (see .env.example, supabase/schema.sql).
 */

export async function getPortfolioItems(): Promise<PortfolioItem[]> {
  if (!supabase) return portfolioItems

  const { data, error } = await supabase
    .from('portfolio_items')
    .select('*')
    .order('published_at', { ascending: false })

  if (error || !data) return portfolioItems
  return data.map(mapPortfolioItem)
}

export async function getPortfolioItemBySlug(slug: string): Promise<PortfolioItem | undefined> {
  if (!supabase) return portfolioItems.find((item) => item.slug === slug)

  const { data, error } = await supabase.from('portfolio_items').select('*').eq('slug', slug).single()
  if (error || !data) return portfolioItems.find((item) => item.slug === slug)
  return mapPortfolioItem(data)
}

export async function getBlogPosts(): Promise<BlogPost[]> {
  if (!supabase) return blogPosts.filter((post) => post.status === 'published')

  const { data, error } = await supabase
    .from('blog_posts')
    .select('*')
    .eq('status', 'published')
    .order('published_at', { ascending: false })

  if (error || !data) return blogPosts.filter((post) => post.status === 'published')
  return data.map(mapBlogPost)
}

export async function getBlogPostBySlug(slug: string): Promise<BlogPost | undefined> {
  if (!supabase) return blogPosts.find((post) => post.slug === slug)

  const { data, error } = await supabase.from('blog_posts').select('*').eq('slug', slug).single()
  if (error || !data) return blogPosts.find((post) => post.slug === slug)
  return mapBlogPost(data)
}

export async function getPageBySlug(slug: string): Promise<PageContent | undefined> {
  if (!supabase) return pages.find((page) => page.slug === slug && page.isPublished)

  const { data, error } = await supabase
    .from('pages')
    .select('*')
    .eq('slug', slug)
    .eq('is_published', true)
    .single()

  if (error || !data) return pages.find((page) => page.slug === slug && page.isPublished)
  return mapPageContent(data)
}
