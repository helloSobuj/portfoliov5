-- Portfolio schema: run once in the Supabase SQL editor (or via the CLI:
-- `supabase db push`) against a fresh project. Safe to re-run — every
-- statement is idempotent.
--
-- Tables:
--   profiles        one row per admin/editor user, links to auth.users
--   portfolio_items case studies shown on / and /portfolio
--   blog_posts      posts shown on /blog
--   pages           the HTML/Markdown page builder's published pages
--   messages        contact form submissions
--
-- Row-level security: public (anon) read access is limited to published
-- content; all writes require an authenticated user with a `profiles` row.

create extension if not exists "pgcrypto";

-- ---------------------------------------------------------------------------
-- profiles
-- ---------------------------------------------------------------------------
create table if not exists public.profiles (
  id uuid primary key references auth.users (id) on delete cascade,
  email text not null,
  display_name text not null default '',
  role text not null default 'editor' check (role in ('admin', 'editor')),
  created_at timestamptz not null default now()
);

alter table public.profiles enable row level security;

create or replace function public.is_admin_or_editor(uid uuid)
returns boolean
language sql
stable
security definer
set search_path = public
as $$
  select exists (
    select 1 from public.profiles where id = uid and role in ('admin', 'editor')
  );
$$;

drop policy if exists "profiles are readable by their owner" on public.profiles;
create policy "profiles are readable by their owner"
  on public.profiles for select
  using (auth.uid() = id);

-- ---------------------------------------------------------------------------
-- portfolio_items
-- ---------------------------------------------------------------------------
create table if not exists public.portfolio_items (
  id uuid primary key default gen_random_uuid(),
  slug text not null unique,
  title text not null,
  summary text not null,
  description text not null default '',
  cover_image_url text not null default '',
  gallery text[] not null default '{}',
  category text not null default 'other'
    check (category in ('web', 'mobile', 'design', 'writing', 'other')),
  tags text[] not null default '{}',
  live_url text,
  repo_url text,
  featured boolean not null default false,
  published_at timestamptz not null default now(),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

alter table public.portfolio_items enable row level security;

drop policy if exists "portfolio items are publicly readable" on public.portfolio_items;
create policy "portfolio items are publicly readable"
  on public.portfolio_items for select
  using (true);

drop policy if exists "admins manage portfolio items" on public.portfolio_items;
create policy "admins manage portfolio items"
  on public.portfolio_items for all
  using (public.is_admin_or_editor(auth.uid()))
  with check (public.is_admin_or_editor(auth.uid()));

-- ---------------------------------------------------------------------------
-- blog_posts
-- ---------------------------------------------------------------------------
create table if not exists public.blog_posts (
  id uuid primary key default gen_random_uuid(),
  slug text not null unique,
  title text not null,
  excerpt text not null default '',
  content_markdown text not null default '',
  cover_image_url text,
  tags text[] not null default '{}',
  status text not null default 'draft' check (status in ('draft', 'published')),
  author_id uuid references auth.users (id) on delete set null,
  published_at timestamptz,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

alter table public.blog_posts enable row level security;

drop policy if exists "published posts are publicly readable" on public.blog_posts;
create policy "published posts are publicly readable"
  on public.blog_posts for select
  using (status = 'published' or public.is_admin_or_editor(auth.uid()));

drop policy if exists "admins manage blog posts" on public.blog_posts;
create policy "admins manage blog posts"
  on public.blog_posts for all
  using (public.is_admin_or_editor(auth.uid()))
  with check (public.is_admin_or_editor(auth.uid()));

-- ---------------------------------------------------------------------------
-- pages (HTML/Markdown page builder)
-- ---------------------------------------------------------------------------
create table if not exists public.pages (
  id uuid primary key default gen_random_uuid(),
  slug text not null unique,
  title text not null,
  format text not null default 'markdown' check (format in ('markdown', 'html')),
  body text not null default '',
  is_published boolean not null default false,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

alter table public.pages enable row level security;

drop policy if exists "published pages are publicly readable" on public.pages;
create policy "published pages are publicly readable"
  on public.pages for select
  using (is_published or public.is_admin_or_editor(auth.uid()));

drop policy if exists "admins manage pages" on public.pages;
create policy "admins manage pages"
  on public.pages for all
  using (public.is_admin_or_editor(auth.uid()))
  with check (public.is_admin_or_editor(auth.uid()));

-- ---------------------------------------------------------------------------
-- messages (contact form)
-- ---------------------------------------------------------------------------
create table if not exists public.messages (
  id uuid primary key default gen_random_uuid(),
  name text not null,
  email text not null,
  subject text not null,
  message text not null,
  created_at timestamptz not null default now()
);

alter table public.messages enable row level security;

-- Anyone (including anon) can submit the contact form, but only admins can
-- read submissions back — this is a write-only endpoint from the client's
-- perspective.
drop policy if exists "anyone can submit a message" on public.messages;
create policy "anyone can submit a message"
  on public.messages for insert
  with check (true);

drop policy if exists "admins read messages" on public.messages;
create policy "admins read messages"
  on public.messages for select
  using (public.is_admin_or_editor(auth.uid()));

-- ---------------------------------------------------------------------------
-- updated_at triggers
-- ---------------------------------------------------------------------------
create or replace function public.set_updated_at()
returns trigger
language plpgsql
as $$
begin
  new.updated_at = now();
  return new;
end;
$$;

drop trigger if exists set_updated_at on public.portfolio_items;
create trigger set_updated_at before update on public.portfolio_items
  for each row execute function public.set_updated_at();

drop trigger if exists set_updated_at on public.blog_posts;
create trigger set_updated_at before update on public.blog_posts
  for each row execute function public.set_updated_at();

drop trigger if exists set_updated_at on public.pages;
create trigger set_updated_at before update on public.pages
  for each row execute function public.set_updated_at();
