import { useEffect, useState, type FormEvent } from 'react'
import { Plus, Trash2 } from 'lucide-react'
import { supabase, isSupabaseConfigured } from '@/lib/supabase'
import { mapPortfolioItem, toPortfolioItemRow } from '@/lib/mappers'
import { Button } from '@/components/ui/Button'
import type { PortfolioCategory, PortfolioItem } from '@/types'

const emptyForm = {
  title: '',
  slug: '',
  summary: '',
  description: '',
  coverImageUrl: '',
  category: 'web' as PortfolioCategory,
  tags: '',
  liveUrl: '',
  repoUrl: '',
  featured: false,
}

export default function AdminPortfolioPage() {
  const [items, setItems] = useState<PortfolioItem[]>([])
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
    const { data } = await supabase.from('portfolio_items').select('*').order('created_at', { ascending: false })
    setItems((data ?? []).map(mapPortfolioItem))
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

  function startEdit(item: PortfolioItem) {
    setEditingId(item.id)
    setForm({
      title: item.title,
      slug: item.slug,
      summary: item.summary,
      description: item.description,
      coverImageUrl: item.coverImageUrl,
      category: item.category,
      tags: item.tags.join(', '),
      liveUrl: item.liveUrl ?? '',
      repoUrl: item.repoUrl ?? '',
      featured: item.featured,
    })
    setShowForm(true)
  }

  async function handleSubmit(e: FormEvent) {
    e.preventDefault()
    if (!supabase) return
    setSaving(true)

    const row = toPortfolioItemRow({
      title: form.title,
      slug: form.slug,
      summary: form.summary,
      description: form.description,
      coverImageUrl: form.coverImageUrl,
      category: form.category,
      tags: form.tags.split(',').map((t) => t.trim()).filter(Boolean),
      liveUrl: form.liveUrl,
      repoUrl: form.repoUrl,
      featured: form.featured,
      publishedAt: new Date().toISOString(),
    })

    if (editingId) {
      await supabase.from('portfolio_items').update(row).eq('id', editingId)
    } else {
      await supabase.from('portfolio_items').insert(row)
    }

    setSaving(false)
    setShowForm(false)
    load()
  }

  async function handleDelete(id: string) {
    if (!supabase || !window.confirm('Delete this project?')) return
    await supabase.from('portfolio_items').delete().eq('id', id)
    load()
  }

  return (
    <div>
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-semibold">Portfolio items</h1>
        <Button type="button" onClick={startCreate} disabled={!isSupabaseConfigured}>
          <Plus size={16} aria-hidden />
          New project
        </Button>
      </div>

      {!isSupabaseConfigured && (
        <p className="mt-4 rounded-md border border-border bg-bg-elevated p-3 text-xs text-fg-muted">
          Connect Supabase to create and edit portfolio items (see .env.example).
        </p>
      )}

      {showForm && (
        <form onSubmit={handleSubmit} className="mt-6 flex flex-col gap-4 rounded-lg border border-border p-6">
          <div className="grid gap-4 sm:grid-cols-2">
            <TextField label="Title" value={form.title} onChange={(v) => setForm((f) => ({ ...f, title: v }))} required />
            <TextField label="Slug" value={form.slug} onChange={(v) => setForm((f) => ({ ...f, slug: v }))} required />
          </div>
          <TextField label="Summary" value={form.summary} onChange={(v) => setForm((f) => ({ ...f, summary: v }))} required />
          <div>
            <label className="text-sm font-medium text-fg-muted">Description</label>
            <textarea
              value={form.description}
              onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))}
              rows={4}
              required
              className="mt-2 w-full rounded-md border border-border bg-bg-elevated px-3 py-2 text-sm focus:outline-none"
            />
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <TextField label="Cover image URL" value={form.coverImageUrl} onChange={(v) => setForm((f) => ({ ...f, coverImageUrl: v }))} required />
            <div>
              <label className="text-sm font-medium text-fg-muted">Category</label>
              <select
                value={form.category}
                onChange={(e) => setForm((f) => ({ ...f, category: e.target.value as PortfolioCategory }))}
                className="mt-2 w-full rounded-md border border-border bg-bg-elevated px-3 py-2 text-sm focus:outline-none"
              >
                {['web', 'mobile', 'design', 'writing', 'other'].map((c) => (
                  <option key={c} value={c}>
                    {c}
                  </option>
                ))}
              </select>
            </div>
          </div>
          <TextField label="Tags (comma separated)" value={form.tags} onChange={(v) => setForm((f) => ({ ...f, tags: v }))} />
          <div className="grid gap-4 sm:grid-cols-2">
            <TextField label="Live URL" value={form.liveUrl} onChange={(v) => setForm((f) => ({ ...f, liveUrl: v }))} />
            <TextField label="Repo URL" value={form.repoUrl} onChange={(v) => setForm((f) => ({ ...f, repoUrl: v }))} />
          </div>
          <label className="flex items-center gap-2 text-sm text-fg-muted">
            <input
              type="checkbox"
              checked={form.featured}
              onChange={(e) => setForm((f) => ({ ...f, featured: e.target.checked }))}
              className="h-4 w-4 rounded border-border"
            />
            Featured
          </label>

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
        {!loading && items.length === 0 && <p className="text-fg-muted">No projects yet.</p>}
        {items.map((item) => (
          <li key={item.id} className="flex items-center justify-between gap-4 py-4">
            <div>
              <p className="font-medium">{item.title}</p>
              <p className="text-sm text-fg-muted">/{item.slug}</p>
            </div>
            <div className="flex gap-3">
              <button type="button" onClick={() => startEdit(item)} className="text-sm text-fg-muted hover:text-fg cursor-pointer">
                Edit
              </button>
              <button
                type="button"
                onClick={() => handleDelete(item.id)}
                className="text-sm text-fg-muted hover:text-accent cursor-pointer"
                aria-label={`Delete ${item.title}`}
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
