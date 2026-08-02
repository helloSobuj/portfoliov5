import type { BlogPost, PageContent, PortfolioItem } from '@/types'

/** Maps snake_case Supabase rows (see supabase/schema.sql) to the app's camelCase types. */

// eslint-disable-next-line @typescript-eslint/no-explicit-any
type Row = Record<string, any>

export function mapPortfolioItem(row: Row): PortfolioItem {
  return {
    id: row.id,
    slug: row.slug,
    title: row.title,
    summary: row.summary,
    description: row.description,
    coverImageUrl: row.cover_image_url,
    gallery: row.gallery ?? [],
    category: row.category,
    tags: row.tags ?? [],
    liveUrl: row.live_url ?? undefined,
    repoUrl: row.repo_url ?? undefined,
    featured: row.featured,
    publishedAt: row.published_at,
    createdAt: row.created_at,
    updatedAt: row.updated_at,
  }
}

export function mapBlogPost(row: Row): BlogPost {
  return {
    id: row.id,
    slug: row.slug,
    title: row.title,
    excerpt: row.excerpt,
    contentMarkdown: row.content_markdown,
    coverImageUrl: row.cover_image_url ?? undefined,
    tags: row.tags ?? [],
    status: row.status,
    authorId: row.author_id,
    publishedAt: row.published_at,
    createdAt: row.created_at,
    updatedAt: row.updated_at,
  }
}

export function mapPageContent(row: Row): PageContent {
  return {
    id: row.id,
    slug: row.slug,
    title: row.title,
    format: row.format,
    body: row.body,
    isPublished: row.is_published,
    createdAt: row.created_at,
    updatedAt: row.updated_at,
  }
}

export function toPortfolioItemRow(item: Partial<PortfolioItem>): Row {
  return {
    slug: item.slug,
    title: item.title,
    summary: item.summary,
    description: item.description,
    cover_image_url: item.coverImageUrl,
    gallery: item.gallery,
    category: item.category,
    tags: item.tags,
    live_url: item.liveUrl || null,
    repo_url: item.repoUrl || null,
    featured: item.featured,
    published_at: item.publishedAt,
  }
}

export function toBlogPostRow(post: Partial<BlogPost>): Row {
  return {
    slug: post.slug,
    title: post.title,
    excerpt: post.excerpt,
    content_markdown: post.contentMarkdown,
    cover_image_url: post.coverImageUrl || null,
    tags: post.tags,
    status: post.status,
    published_at: post.publishedAt,
  }
}

export function toPageContentRow(page: Partial<PageContent>): Row {
  return {
    slug: page.slug,
    title: page.title,
    format: page.format,
    body: page.body,
    is_published: page.isPublished,
  }
}
