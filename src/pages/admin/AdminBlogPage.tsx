import { useEffect, useState, type FormEvent } from 'react'
import { Plus, Trash2 } from 'lucide-react'
import { supabase, isSupabaseConfigured } from '@/lib/supabase'
import { mapBlogPost, toBlogPostRow } from '@/lib/mappers'
import { Button } from '@/components/ui/Button'
import { MarkdownEditor } from '@/components/editor/MarkdownEditor'
import type { BlogPost, BlogStatus } from '@/types'

const emptyForm = {
  title: '',
  slug: '',
  excerpt: '',
  contentMarkdown: '',
  coverImageUrl: '',
  tags: '',
  status: 'draft' as BlogStatus,
}

export default function AdminBlogPage() {
  const [posts, setPosts] = useState<BlogPost[]>([])
  const [loading, setLoading] = useState(true)
  const [editingId, setEditingId] = useState<string | null>(null)
  const [form, setForm] = useState(emptyForm)
  const [saving, setSaving] = useState(false)
  const [showForm, setShowForm] = useState(false)

  async function load() {
    if (!supabase) {
      setLoading(false)
      return
    }
    setLoading(true)
    const { data } = await supabase.from('blog_posts').select('*').order('created_at', { ascending: false })
    setPosts((data ?? []).map(mapBlogPost))
    setLoading(false)
  }

  useEffect(() => {
    load()
  }, [])

  function startCreate() {
    setEditingId(null)
    setForm(emptyForm)
    setShowForm(true)
  }

  function startEdit(post: BlogPost) {
    setEditingId(post.id)
    setForm({
      title: post.title,
      slug: post.slug,
      excerpt: post.excerpt,
      contentMarkdown: post.contentMarkdown,
      coverImageUrl: post.coverImageUrl ?? '',
      tags: post.tags.join(', '),
      status: post.status,
    })
    setShowForm(true)
  }

  async function handleSubmit(e: FormEvent) {
    e.preventDefault()
    if (!supabase) return
    setSaving(true)

    const row = toBlogPostRow({
      title: form.title,
      slug: form.slug,
      excerpt: form.excerpt,
      contentMarkdown: form.contentMarkdown,
      coverImageUrl: form.coverImageUrl,
      tags: form.tags.split(',').map((t) => t.trim()).filter(Boolean),
      status: form.status,
      publishedAt: form.status === 'published' ? new Date().toISOString() : null,
    })

    if (editingId) {
      await supabase.from('blog_posts').update(row).eq('id', editingId)
    } else {
      await supabase.from('blog_posts').insert(row)
    }

    setSaving(false)
    setShowForm(false)
    load()
  }

  async function handleDelete(id: string) {
    if (!supabase || !window.confirm('Delete this post?')) return
    await supabase.from('blog_posts').delete().eq('id', id)
    load()
  }

  return (
    <div>
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-semibold">Blog posts</h1>
        <Button type="button" onClick={startCreate} disabled={!isSupabaseConfigured}>
          <Plus size={16} aria-hidden />
          New post
        </Button>
      </div>

      {!isSupabaseConfigured && (
        <p className="mt-4 rounded-md border border-border bg-bg-elevated p-3 text-xs text-fg-muted">
          Connect Supabase to create and edit blog posts (see .env.example).
        </p>
      )}

      {showForm && (
        <form onSubmit={handleSubmit} className="mt-6 flex flex-col gap-4 rounded-lg border border-border p-6">
          <div className="grid gap-4 sm:grid-cols-2">
            <TextField label="Title" value={form.title} onChange={(v) => setForm((f) => ({ ...f, title: v }))} required />
            <TextField label="Slug" value={form.slug} onChange={(v) => setForm((f) => ({ ...f, slug: v }))} required />
          </div>
          <TextField label="Excerpt" value={form.excerpt} onChange={(v) => setForm((f) => ({ ...f, excerpt: v }))} required />
          <div className="grid gap-4 sm:grid-cols-2">
            <TextField label="Cover image URL" value={form.coverImageUrl} onChange={(v) => setForm((f) => ({ ...f, coverImageUrl: v }))} />
            <TextField label="Tags (comma separated)" value={form.tags} onChange={(v) => setForm((f) => ({ ...f, tags: v }))} />
          </div>

          <div>
            <label className="text-sm font-medium text-fg-muted">Status</label>
            <select
              value={form.status}
              onChange={(e) => setForm((f) => ({ ...f, status: e.target.value as BlogStatus }))}
              className="mt-2 w-full max-w-40 rounded-md border border-border bg-bg-elevated px-3 py-2 text-sm focus:outline-none"
            >
              <option value="draft">Draft</option>
              <option value="published">Published</option>
            </select>
          </div>

          <MarkdownEditor
            label="Content"
            format="markdown"
            value={form.contentMarkdown}
            onChange={(v) => setForm((f) => ({ ...f, contentMarkdown: v }))}
          />

          <div className="flex gap-3">
            <Button type="submit" disabled={saving}>
              {saving ? 'Saving…' : 'Save'}
            </Button>
            <Button type="button" variant="secondary" onClick={() => setShowForm(false)}>
              Cancel
            </Button>
          </div>
        </form>
      )}

      <ul className="mt-8 flex flex-col divide-y divide-border">
        {loading && <p className="text-fg-muted">Loading…</p>}
        {!loading && posts.length === 0 && <p className="text-fg-muted">No posts yet.</p>}
        {posts.map((post) => (
          <li key={post.id} className="flex items-center justify-between gap-4 py-4">
            <div>
              <p className="font-medium">{post.title}</p>
              <p className="text-sm text-fg-muted">
                /{post.slug} · {post.status}
              </p>
            </div>
            <div className="flex gap-3">
              <button type="button" onClick={() => startEdit(post)} className="text-sm text-fg-muted hover:text-fg cursor-pointer">
                Edit
              </button>
              <button
                type="button"
                onClick={() => handleDelete(post.id)}
                className="text-sm text-fg-muted hover:text-accent cursor-pointer"
                aria-label={`Delete ${post.title}`}
              >
                <Trash2 size={16} aria-hidden />
              </button>
            </div>
          </li>
        ))}
      </ul>
    </div>
  )
}

function TextField({
  label,
  value,
  onChange,
  required = false,
}: {
  label: string
  value: string
  onChange: (v: string) => void
  required?: boolean
}) {
  return (
    <div>
      <label className="text-sm font-medium text-fg-muted">{label}</label>
      <input
        value={value}
        required={required}
        onChange={(e) => onChange(e.target.value)}
        className="mt-2 w-full rounded-md border border-border bg-bg-elevated px-3 py-2 text-sm focus:outline-none"
      />
    </div>
  )
}
