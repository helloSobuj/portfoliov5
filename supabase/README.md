# Supabase setup

1. Create a project at [supabase.com](https://supabase.com/dashboard).
2. Open the SQL Editor and run `schema.sql` from this folder (creates
   `profiles`, `portfolio_items`, `blog_posts`, `pages`, `messages`, and
   their row-level security policies).
3. In Project Settings → API, copy the **Project URL** and **anon/public
   key** into your `.env` (see `.env.example` at the repo root).
4. Create your admin user:
   - Authentication → Users → Add user (email + password, or invite by
     email).
   - Table Editor → `profiles` → insert a row with `id` = that user's UUID,
     `email`, and `role = 'admin'`.
5. Sign in at `/admin/login` with that email/password.

## Notes

- `portfolio_items` and published `blog_posts` / `pages` are readable by
  anyone (the public site uses the anon key); all writes require a
  `profiles` row with `role in ('admin', 'editor')`.
- `messages` (contact form) is insert-only for anonymous visitors — only
  admins/editors can read submissions back, so treat the Table Editor (or
  a Supabase Edge Function forwarding to email) as the inbox.
- Regenerate TypeScript types from the live schema with the Supabase CLI:
  `supabase gen types typescript --project-id <ref> > src/types/database.ts`.
