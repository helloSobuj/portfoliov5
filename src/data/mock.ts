import type { BlogPost, PageContent, PortfolioItem, QuickStat, Service } from '@/types'

/**
 * Placeholder content shown until a Supabase project is connected
 * (see .env.example + supabase/schema.sql). Every fetch helper in
 * src/lib/data.ts falls back to this when `isSupabaseConfigured` is false.
 */

export const quickStats: QuickStat[] = [
  { label: 'Years experience', value: '6+' },
  { label: 'Projects shipped', value: '40+' },
  { label: 'Happy clients', value: '25+' },
  { label: 'Open-source stars', value: '1.2k' },
]

export const services: Service[] = [
  {
    title: 'Product Engineering',
    description: 'End-to-end React/TypeScript builds, from prototype to production.',
    icon: 'Code2',
  },
  {
    title: 'UI/UX Design',
    description: 'Interface design systems with a focus on clarity and motion.',
    icon: 'PenTool',
  },
  {
    title: 'Consulting',
    description: 'Architecture reviews, performance audits, and team mentoring.',
    icon: 'Compass',
  },
]

export const portfolioItems: PortfolioItem[] = [
  {
    id: '1',
    slug: 'realtime-dashboard',
    title: 'Realtime Analytics Dashboard',
    summary: 'A live metrics dashboard built with React, Supabase realtime, and D3.',
    description:
      'A live metrics dashboard built with React, Supabase realtime, and D3. Streams event data over websockets and renders it with animated, accessible charts.',
    coverImageUrl: 'https://images.unsplash.com/photo-1551288049-bebda4e38f71?w=1200&q=80',
    gallery: [],
    category: 'web',
    tags: ['React', 'Supabase', 'D3'],
    liveUrl: '',
    repoUrl: '',
    featured: true,
    publishedAt: '2025-11-01',
    createdAt: '2025-11-01',
    updatedAt: '2025-11-01',
  },
  {
    id: '2',
    slug: 'motion-design-system',
    title: 'Motion Design System',
    summary: 'A Framer Motion + GSAP component library used across three products.',
    description:
      'A Framer Motion + GSAP component library used across three products, documented with interactive examples and accessibility notes for reduced-motion users.',
    coverImageUrl: 'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=1200&q=80',
    gallery: [],
    category: 'design',
    tags: ['Design Systems', 'Framer Motion', 'GSAP'],
    featured: true,
    publishedAt: '2025-08-14',
    createdAt: '2025-08-14',
    updatedAt: '2025-08-14',
  },
  {
    id: '3',
    slug: 'field-notes-app',
    title: 'Field Notes',
    summary: 'Offline-first mobile app for researchers, built with React Native.',
    description:
      'Offline-first mobile app for field researchers, built with React Native and a local-first sync engine backed by Supabase.',
    coverImageUrl: 'https://images.unsplash.com/photo-1526498460520-4c246339dccb?w=1200&q=80',
    gallery: [],
    category: 'mobile',
    tags: ['React Native', 'Offline-first'],
    featured: false,
    publishedAt: '2025-05-02',
    createdAt: '2025-05-02',
    updatedAt: '2025-05-02',
  },
]

export const blogPosts: BlogPost[] = [
  {
    id: '1',
    slug: 'orchestrating-gsap-and-framer-motion',
    title: 'Orchestrating GSAP and Framer Motion in the same app',
    excerpt: 'Where each tool wins, and how to stop them from fighting over the same element.',
    contentMarkdown: `## Two motion libraries, one app

GSAP is best for **timeline-driven, route-level transitions** — it doesn't
care about React's render cycle, so it's a natural fit for orchestrating
entrances and exits across a page.

Framer Motion is best for **state-driven micro-interactions** — hover,
tap, drag, and layout animations that are naturally expressed as a
function of component state.

### The rule of thumb

- Page transitions, scroll-triggered reveals → GSAP
- Buttons, cards, toggles, modals → Framer Motion

Keep them on separate elements and they never conflict.`,
    coverImageUrl: 'https://images.unsplash.com/photo-1550439062-609e1531270e?w=1200&q=80',
    tags: ['Animation', 'React'],
    status: 'published',
    authorId: 'seed',
    publishedAt: '2025-12-01',
    createdAt: '2025-12-01',
    updatedAt: '2025-12-01',
  },
  {
    id: '2',
    slug: 'designing-a-day-night-theme',
    title: 'Designing an accessible day/night theme',
    excerpt: 'Contrast ratios, color tokens, and the trick to avoiding a flash of the wrong theme.',
    contentMarkdown: `Every color pair in this theme is checked against **WCAG AA (4.5:1)**
for body text before it ships. The toggle persists to \`localStorage\` and
a tiny inline script in \`index.html\` applies the theme class before
React hydrates, so there's no flash of the wrong theme on load.`,
    coverImageUrl: 'https://images.unsplash.com/photo-1502691876148-a84978e59af8?w=1200&q=80',
    tags: ['Design', 'Accessibility'],
    status: 'published',
    authorId: 'seed',
    publishedAt: '2025-10-18',
    createdAt: '2025-10-18',
    updatedAt: '2025-10-18',
  },
]

export const pages: PageContent[] = [
  {
    id: '1',
    slug: 'now',
    title: 'Now',
    format: 'markdown',
    body: '## What I\'m doing now\n\nBuilding this portfolio, and writing about motion design on the blog.',
    isPublished: true,
    createdAt: '2026-01-01',
    updatedAt: '2026-01-01',
  },
]
