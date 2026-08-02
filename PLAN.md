# Portfolio — Architecture & Delivery Plan

Status: **Phase 1 (MVP) scaffolded on this branch.** Everything described
as "implemented" below exists in the repo today; everything under
"Phase 2+" is scoped but not built yet.

## 1. Goals & constraints

- Multi-page personal portfolio (not a single-page scroller): Home,
  Portfolio (grid + detail), Blog (list + post), a Markdown/HTML custom
  page builder, Contact, About, and a separate role-gated Admin panel.
- React + TypeScript, Vite-based, no Next.js.
- GSAP for page-level transitions, Framer Motion for micro-interactions —
  kept on separate elements so the two libraries never fight over the
  same node.
- Accessible day/night theme (WCAG AA, no flash-of-wrong-theme).
- Supabase for data + admin auth; Vercel for hosting.
- Aesthetic target: reactbits.dev / 21st.dev — dark-first, restrained,
  large imagery, generous whitespace, one accent color used sparingly.

## 2. Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│ Vercel (static hosting + env vars)                               │
│                                                                   │
│  ┌───────────────────────────────────────────────────────────┐  │
│  │ Vite build (React SPA, client-side routed)                 │  │
│  │                                                             │  │
│  │  App.tsx                                                   │  │
│  │   ├─ ThemeProvider (day/night, localStorage + prefers-*)   │  │
│  │   ├─ AuthProvider (Supabase session + profile/role)        │  │
│  │   └─ BrowserRouter                                         │  │
│  │       ├─ Public Layout (Header, PageTransition[GSAP],      │  │
│  │       │   Footer)                                          │  │
│  │       │    ├─ / (Home: Hero, QuickStats, About teaser,     │  │
│  │       │    │      PortfolioGalleryTabs, Services, CTA)     │  │
│  │       │    ├─ /portfolio, /portfolio/:slug                 │  │
│  │       │    ├─ /blog, /blog/:slug                           │  │
│  │       │    ├─ /about, /contact                             │  │
│  │       │    └─ /page/:slug   (custom Markdown/HTML pages)   │  │
│  │       └─ /admin/*  (code-split, lazy-loaded)                │  │
│  │            ├─ /admin/login                                 │  │
│  │            └─ ProtectedRoute → AdminLayout                 │  │
│  │                 ├─ /admin            dashboard              │  │
│  │                 ├─ /admin/portfolio  CRUD (PortfolioItem)   │  │
│  │                 ├─ /admin/blog       CRUD (BlogPost)        │  │
│  │                 └─ /admin/pages      CRUD (PageContent)     │  │
│  └───────────────────────────────────────────────────────────┘  │
└───────────────────────────┬───────────────────────────────────-─┘
                             │ supabase-js (anon key, RLS-enforced)
                             ▼
┌────────────────────────────────────────────────────────────────┐
│ Supabase project                                                 │
│  Auth            email/password admin users                      │
│  Postgres tables profiles, portfolio_items, blog_posts, pages,   │
│                   messages  (see supabase/schema.sql)            │
│  RLS policies    public read of published content; writes        │
│                   require a profiles row with role admin/editor  │
└────────────────────────────────────────────────────────────────┘
```

**Data flow.** `src/lib/data.ts` is the single read path every public page
uses (`getPortfolioItems`, `getBlogPosts`, `getPageBySlug`, …). It calls
Supabase when `VITE_SUPABASE_URL`/`VITE_SUPABASE_ANON_KEY` are set, and
falls back to `src/data/mock.ts` otherwise — so the site is fully
browsable before a backend exists, and demos/tests don't need a live
project. `src/lib/mappers.ts` translates between Supabase's snake_case
rows and the app's camelCase domain types in both directions (`mapX` for
reads, `toXRow` for writes).

**Why this split (Home/Portfolio/Blog/Custom pages/Admin as separate
route trees):** it keeps the admin bundle (Supabase writes, markdown
editor, form validation) out of the bundle every anonymous visitor
downloads — `/admin/*` is `React.lazy`-loaded — and keeps RLS the single
source of truth for who can write what, rather than duplicating
authorization logic in the UI.

## 3. Tech stack & rationale

| Concern            | Choice                          | Why |
| ------------------- | -------------------------------- | --- |
| Framework           | React 19 + TypeScript, Vite      | Requested explicitly; Vite gives fast HMR without Next.js's server runtime, which isn't needed for a client-rendered portfolio. |
| Routing             | react-router-dom v7 (library mode) | Standard for Vite SPAs; nested routes map cleanly onto the public/admin split. |
| Styling             | Tailwind CSS v4 (CSS-first `@theme`) | Design tokens (`--color-bg`, `--color-accent`, …) double as the theme system; utility classes keep component files self-contained. |
| Page transitions    | GSAP (`gsap.context`, timeline per route) | Timeline-driven, framework-agnostic — ideal for "the whole page enters/exits," independent of component state. |
| Micro-interactions  | Framer Motion (`whileInView`, `AnimatePresence`, layout animations) | State-driven — natural fit for hover/tap/filter/stagger effects tied to component state. |
| Data/auth backend   | Supabase (Postgres + Auth + RLS) | Requested; RLS lets the client talk to Postgres directly and safely, no custom API server needed. |
| Content formats     | react-markdown + remark-gfm (Markdown), DOMPurify (HTML sanitization) | The custom page builder accepts either format; HTML is always sanitized before `dangerouslySetInnerHTML`, both in the editor preview and on the published page. |
| Forms/validation    | Zod                               | Runtime validation for the contact form (and easy to reuse for admin forms as they grow). |
| Icons               | Lucide (+ 3 hand-written brand glyphs) | Lucide dropped trademarked brand icons (GitHub/LinkedIn/X), so `src/components/icons/BrandIcons.tsx` supplies minimal outline replacements. |
| Deployment          | Vercel                            | Requested; zero-config for a Vite SPA. |

## 4. Theme system (day/night)

- Tokens are CSS custom properties (`--bg`, `--fg`, `--fg-muted`,
  `--border`, `--accent`, `--accent-fg`) defined once for "night" (dark,
  default) and overridden under `.light` for "day" — see
  `src/index.css`.
- `ThemeContext` toggles a `dark`/`light` class on `<html>` and persists
  the choice to `localStorage`; a small inline script in `index.html`
  applies the stored (or OS-preferred) theme *before* React hydrates, so
  there's no flash of the wrong theme.
- Every text/background pair is checked against **WCAG AA (4.5:1)** for
  body text — the accent color is a different shade in each theme
  specifically to hold that ratio against both backgrounds.
- `prefers-reduced-motion: reduce` disables animation duration globally
  (`src/index.css`) and is checked explicitly before the GSAP page
  transition runs.

## 5. Data models

Domain types live in `src/types/index.ts`; Postgres DDL (snake_case,
with RLS) is in `supabase/schema.sql`.

- **PortfolioItem** — slug, title, summary/description, cover + gallery
  images, category (`web`/`mobile`/`design`/`writing`/`other`), tags,
  optional live/repo URLs, `featured`, publish/created/updated timestamps.
- **BlogPost** — slug, title, excerpt, `contentMarkdown`, cover image,
  tags, `status` (`draft`/`published`), author, publish/created/updated
  timestamps.
- **PageContent** — the Markdown/HTML builder's unit: slug, title,
  `format` (`markdown`/`html`), `body`, `isPublished`. Served publicly at
  `/page/:slug`.
- **Profile** — `id` (= `auth.users.id`), email, `displayName`, `role`
  (`admin`/`editor`). Drives both the admin UI and RLS write policies.
- **ContactMessage** — name, email, subject, message; insert-only from
  the public form.

## 6. Auth plan

- **Provider:** Supabase Auth, email/password. No self-serve signup flow
  is exposed — admins are provisioned manually (Supabase dashboard →
  Authentication → Add user), matching a single-owner portfolio's needs.
- **Roles:** a `profiles` table (`id`, `email`, `display_name`, `role`)
  holds `admin` or `editor` per user. `admin` and `editor` currently have
  identical UI access (both can manage all content); the role column
  exists so a future "editor can't delete / can't publish" split is a
  policy change, not a schema migration.
- **Enforcement is in Postgres, not just the client.** Every write policy
  in `schema.sql` calls `is_admin_or_editor(auth.uid())`; the anon key is
  safe to ship in client code because RLS — not UI logic — is what
  actually blocks unauthorized writes. `ProtectedRoute` (client-side)
  only exists for UX (redirect to `/admin/login`), not as a security
  boundary.
- **Session handling:** `AuthContext` subscribes to
  `supabase.auth.onAuthStateChange`, loads the matching `profiles` row,
  and exposes `{ session, profile, loading, signIn, signOut }` to the
  rest of the app.

## 7. Deliverables

### Done (this branch)

- [x] Vite + React + TypeScript scaffold, `package.json` scripts
      (`dev`, `build`, `preview`, `lint`)
- [x] This architecture doc
- [x] Day/night theme (`ThemeContext`, `ThemeToggle`, WCAG-checked tokens)
- [x] Core components: `Header`, `Footer`, `Layout`, `PageTransition`
      (GSAP), `PortfolioGrid`, `PortfolioCard`, `BlogList`, `BlogCard`,
      `MarkdownEditor`, `PageEditor`, `ThemeToggle`, `AdminLayout`,
      `ProtectedRoute`
- [x] All requested pages: Home (Header/Hero/QuickStats/About/tabbed
      gallery/Services/CTA/Footer), Portfolio grid + detail, Blog list +
      post, Custom page builder + public renderer, Contact (form +
      optional map embed), About, Admin (login + dashboard + 3 CRUD
      sections)
- [x] Data models (`src/types`) + Supabase schema with RLS
      (`supabase/schema.sql`)
- [x] Data-access layer with mock-content fallback
      (`src/lib/data.ts`, `src/data/mock.ts`)
- [x] Contact form with Zod validation, writes to `messages` table
- [x] HTML sanitization (DOMPurify) for the custom page builder
- [x] Auth plan implemented (`AuthContext`, admin login,
      role-gated routes)
- [x] Verified: `tsc -b` clean, `vite build` succeeds, `oxlint` clean
      (2 informational fast-refresh warnings only), all routes smoke-
      tested in a headless browser with zero console/page errors

### Not yet done (see Phase 2+ below)

- [ ] A live Supabase project (schema is written; provisioning is a
      one-time manual step — see `supabase/README.md`)
- [ ] Real content (everything currently shown is placeholder copy/
      Unsplash imagery)
- [ ] Image upload (admin forms take image *URLs*; no Supabase Storage
      integration yet)
- [ ] Automated tests

## 8. Phased plan

### Phase 0 — Scaffold (done, this branch)
Project setup, theming, routing, all page shells with mock data, admin
CRUD UI, Supabase schema. Goal: a fully click-through-able, type-safe,
accessible site with zero backend dependency.

### Phase 1 — MVP launch
1. Provision the Supabase project, run `schema.sql`, create the first
   admin user (`supabase/README.md`).
2. Replace mock content: real name/bio/photo (`src/data/site.ts`,
   `AboutPage`), 3–6 real portfolio items, 2–3 real blog posts, entered
   through the admin panel (or seeded via SQL).
3. Wire the contact form's `messages` table to a notification — simplest
   path is a Supabase Database Webhook → a small Edge Function → email
   (Resend/SendGrid), so submissions don't just sit in a table.
4. Deploy to Vercel, set env vars, point the domain.
5. Run Lighthouse (target: Accessibility 100, Performance ≥ 90) and fix
   anything that regresses once real images are in place.

### Phase 2 — Enhancements
- **Image handling:** Supabase Storage bucket + upload widget in the
  admin forms (replacing raw URL fields); responsive `<img srcset>` /
  `loading="lazy"` audit.
- **SEO:** per-page `<title>`/meta via a small head-management hook,
  `sitemap.xml` + `robots.txt` generation, Open Graph images per
  portfolio item/post.
- **Editor role split:** restrict `editor` from deleting/publishing per
  the RLS policy scaffolding already in place.
- **Search/filtering:** full-text search across blog posts (Postgres
  `tsvector` + a search box), portfolio filtering by tag as well as
  category.
- **Bundle size:** the current production bundle is ~226 kB gzipped in
  one chunk (admin is already split out); next step is route-based
  `React.lazy` for the public pages too, plus swapping `react-markdown`
  for a lighter renderer if blog volume grows.
- **Testing:** Vitest + React Testing Library for components/data layer,
  Playwright for the public-page smoke path (the manual check done for
  this scaffold should become a CI step).

### Phase 3 — Nice-to-haves
- Analytics (Vercel Analytics or Plausible)
- RSS feed for the blog
- Comments on blog posts (Supabase table + moderation queue)
- Multi-author support (already modeled via `blog_posts.author_id`)
- Draft preview links (share an unpublished page/post via a signed URL)

## 9. Branding & UX decisions

- **Palette:** dark-first (`night` is the default theme), single accent
  color (warm orange, distinct per theme to hold 4.5:1 contrast — see
  §4), solid backgrounds only — no gradients, glows, or grain, per the
  reactbits/21st.dev reference aesthetic and to avoid the generic
  "violet gradient on dark" AI-generated look.
- **Typography:** Space Grotesk for headings, Inter for body — a
  geometric/humanist pairing common in developer portfolios, distinct
  from Anthropic's own Poppins/Lora brand pairing (not reused here,
  since this is a personal site, not Anthropic-branded material).
- **Spacing:** section rhythm follows a 112px/64px/24px scale
  (`Section` component's `compact` prop), large imagery (portfolio cards
  are image-first, minimal overlaid text).
- **Motion:** one page-level transition (GSAP fade/lift on route change)
  plus targeted micro-interactions (Framer Motion: stagger-in hero text,
  `whileInView` reveals, tab-filtered gallery, theme toggle). Deliberately
  avoids stacking multiple hover effects (scale *and* shadow *and* glow)
  on one element.
- **UX psychology applied deliberately:**
  - *Progressive disclosure* — tabbed portfolio gallery on Home shows a
    subset before linking to the full grid.
  - *Recognition over recall* — sticky header with active-route
    highlighting, breadcrumb-style "back to portfolio/blog" links on
    detail pages.
  - *Loss-averse, non-dark-pattern CTA copy* — "Have a project in mind?"
    invites rather than pressures; no fake scarcity/urgency anywhere.
  - *Reduced choice* — portfolio category filter is capped at 5 tabs.
- **Accessibility:** skip-to-content link, visible focus rings
  (`:focus-visible`), `aria-current`/`aria-selected`/`aria-pressed` on
  nav/tabs/toggle, form fields tied to labels and error text via
  `aria-describedby`, `prefers-reduced-motion` respected by both the CSS
  and the GSAP transition.

## 10. Local development

```bash
npm install
cp .env.example .env   # optional — the app runs on mock data without it
npm run dev
```

Full instructions (scripts, folder structure, Supabase setup, Vercel
deploy) are in [`README.md`](./README.md) and
[`supabase/README.md`](./supabase/README.md).

## 11. Known trade-offs / follow-ups

- `react-router-dom@7.18.2` currently carries a GHSA advisory
  (RSC-mode CSRF) — it only applies to React Router's RSC/data-mode
  server actions, which this SPA doesn't use; worth re-checking on the
  next router upgrade.
- Admin CRUD forms take plain image URLs rather than uploads (Phase 2).
- No automated test suite yet — verification for this scaffold was
  `tsc -b` + `vite build` + `oxlint` + a headless-browser smoke pass
  over every route (see §7); Phase 2 turns that into CI.
