# Portfolio

A multi-page personal portfolio built with React + TypeScript, GSAP page
transitions, Framer Motion micro-interactions, an accessible day/night
theme, and a Supabase-backed admin panel for editing portfolio items, blog
posts, and custom Markdown/HTML pages.

See [`PLAN.md`](./PLAN.md) for the full architecture, data model, and
phased roadmap.

## Stack

- **React 19 + TypeScript**, built with **Vite**
- **Tailwind CSS v4** for styling, day/night theme via CSS custom properties
- **GSAP** for route-level page transitions, **Framer Motion** for
  component-level micro-interactions
- **React Router** for client-side routing
- **Supabase** (Postgres + Auth) for portfolio items, blog posts, custom
  pages, and admin auth — see [`supabase/README.md`](./supabase/README.md)
- **Vercel** for deployment

## Getting started

```bash
npm install
cp .env.example .env   # then fill in your Supabase project URL + anon key
npm run dev
```

The app runs fully on bundled mock content (`src/data/mock.ts`) if
`.env` is left unconfigured — everything except the admin panel works
out of the box. To enable the admin panel and real content, follow
[`supabase/README.md`](./supabase/README.md).

## Scripts

| Command           | Description                              |
| ------------------ | ----------------------------------------- |
| `npm run dev`       | Start the Vite dev server                 |
| `npm run build`     | Type-check (`tsc -b`) and build for prod  |
| `npm run preview`   | Preview the production build locally      |
| `npm run lint`      | Run Oxlint                                |

## Project structure

```
src/
  components/   layout, ui primitives, and per-domain components
    layout/       Header, Footer, Layout, PageTransition (GSAP)
    home/         Hero, QuickStats, PortfolioGalleryTabs, Services, CTA
    portfolio/    PortfolioGrid, PortfolioCard
    blog/         BlogList, BlogCard
    editor/       MarkdownEditor, PageEditor (HTML/Markdown builder)
    admin/        ProtectedRoute, AdminLayout
    contact/      ContactForm
  pages/        one file per route, incl. pages/admin/*
  context/      ThemeContext, AuthContext
  lib/          supabase client, data-access layer, mappers, sanitize
  types/        PortfolioItem, BlogPost, PageContent, Profile
  data/         site config + mock content fallback
supabase/
  schema.sql    tables + row-level security policies
  README.md     Supabase project setup steps
```

## Deploying

1. Push this repo to GitHub.
2. Import it into [Vercel](https://vercel.com/new) (framework preset:
   Vite).
3. Add `VITE_SUPABASE_URL` and `VITE_SUPABASE_ANON_KEY` (and optionally
   `VITE_CONTACT_MAP_EMBED_URL`) as environment variables in the Vercel
   project settings.
4. Deploy — the build command is `npm run build`, output directory
   `dist`.
