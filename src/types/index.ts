export type Role = 'admin' | 'editor'

export interface Profile {
  id: string
  email: string
  role: Role
  displayName: string
}

export type PortfolioCategory = 'web' | 'mobile' | 'design' | 'writing' | 'other'

export interface PortfolioItem {
  id: string
  slug: string
  title: string
  summary: string
  description: string
  coverImageUrl: string
  gallery: string[]
  category: PortfolioCategory
  tags: string[]
  liveUrl?: string
  repoUrl?: string
  featured: boolean
  publishedAt: string
  createdAt: string
  updatedAt: string
}

export type BlogStatus = 'draft' | 'published'

export interface BlogPost {
  id: string
  slug: string
  title: string
  excerpt: string
  contentMarkdown: string
  coverImageUrl?: string
  tags: string[]
  status: BlogStatus
  authorId: string
  publishedAt: string | null
  createdAt: string
  updatedAt: string
}

export type PageContentFormat = 'markdown' | 'html'

export interface PageContent {
  id: string
  slug: string
  title: string
  format: PageContentFormat
  body: string
  isPublished: boolean
  createdAt: string
  updatedAt: string
}

export interface ContactMessage {
  id: string
  name: string
  email: string
  subject: string
  message: string
  createdAt: string
}

export interface QuickStat {
  label: string
  value: string
}

export interface Service {
  title: string
  description: string
  icon: string
}
