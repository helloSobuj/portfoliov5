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
    slug: 'redbolt-it',
    title: 'RedBolt IT',
    summary: 'Tech agency website with services, portfolio, and product showcase.',
    description:
      "A tech agency website for RedBolt IT — a bold \"We Build Dreams\" hero, services and product navigation, and social/contact details surfaced right in the header for fast client outreach.",
    coverImageUrl: '/portfolio/redbolt-it.svg',
    gallery: [],
    category: 'web',
    tags: ['Agency', 'Web Design', 'Business Website'],
    liveUrl: 'https://redboltit.com',
    repoUrl: '',
    featured: true,
    publishedAt: '2025-11-01',
    createdAt: '2025-11-01',
    updatedAt: '2025-11-01',
  },
  {
    id: '2',
    slug: 'tachtonic',
    title: 'Tachtonic',
    summary: 'Multi-category electronics e-commerce storefront with promotions and product carousels.',
    description:
      'An e-commerce storefront for Tachtonic — homepage promo carousels (Apple Shopping Event, countdown deals), a popular-categories grid spanning headsets to drones, and full cart/wishlist/account tooling in the header.',
    coverImageUrl: '/portfolio/tachtonic.svg',
    gallery: [],
    category: 'web',
    tags: ['E-commerce', 'Retail', 'Web Design'],
    liveUrl: 'https://gulfuniongate.com',
    repoUrl: '',
    featured: true,
    publishedAt: '2025-09-20',
    createdAt: '2025-09-20',
    updatedAt: '2025-09-20',
  },
  {
    id: '3',
    slug: 'wanchi-group',
    title: 'Wanchi Group of Company',
    summary: 'Corporate website for a 50-year industrial machinery manufacturing leader.',
    description:
      "A group/corporate website for Wanchi Steel Industrial — a full-bleed heavy-industry hero, a four-pillar value proposition (Engineering Excellence, Global Reach, Sustainable Solutions, Customer-Centric Approach), and a multi-section nav spanning About, Services, Product Category, Corporate Responsibility, and Facilities.",
    coverImageUrl: '/portfolio/wanchi-group.svg',
    gallery: [],
    category: 'web',
    tags: ['Corporate', 'Manufacturing', 'Multi-page'],
    liveUrl: 'https://wanchi.com',
    repoUrl: '',
    featured: true,
    publishedAt: '2025-07-10',
    createdAt: '2025-07-10',
    updatedAt: '2025-07-10',
  },
  {
    id: '4',
    slug: 'aidite-dental',
    title: 'Aidite Dental',
    summary: "Product-focused company site for Aidite Dental's zirconia dental materials.",
    description:
      'A dental company website for Aidite — product-detail layouts (Zirconia Material, feature bullets, spec highlights) backed by an image gallery grid across the product range.',
    coverImageUrl: '/portfolio/aidite-dental.svg',
    gallery: [],
    category: 'web',
    tags: ['Healthcare', 'Product Website', 'Corporate'],
    liveUrl: 'https://aiditedental.com',
    repoUrl: '',
    featured: false,
    publishedAt: '2025-05-02',
    createdAt: '2025-05-02',
    updatedAt: '2025-05-02',
  },
  {
    id: '5',
    slug: 'faraji-logistics',
    title: 'Faraji Logistics',
    summary: 'Supply chain and logistics company website with shipment tracking.',
    description:
      'A company website for Faraji Logistics — a full-width port hero with "Unlock new opportunities for your business" messaging, service highlights (Supply Chain Solutions, Customs Clearing, Freight Forwarding), and a shipment-tracking widget front and center.',
    coverImageUrl: '/portfolio/faraji-logistics.svg',
    gallery: [],
    category: 'web',
    tags: ['Logistics', 'Corporate', 'Web Design'],
    liveUrl: 'https://farajilogistic.com',
    repoUrl: '',
    featured: false,
    publishedAt: '2025-03-18',
    createdAt: '2025-03-18',
    updatedAt: '2025-03-18',
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
