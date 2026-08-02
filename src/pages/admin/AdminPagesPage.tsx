import { useEffect, useState } from 'react'
import { Plus, Trash2 } from 'lucide-react'
import { supabase, isSupabaseConfigured } from '@/lib/supabase'
import { mapPageContent, toPageContentRow } from '@/lib/mappers'
import { Button } from '@/components/ui/Button'
import { PageEditor, type PageEditorValue } from '@/components/editor/PageEditor'
import type { PageContent } from '@/types'

export default function AdminPagesPage() {
  const [pages, setPages] = useState<PageContent[]>([])
  const [loading, setLoading] = useState(true)
  const [editing, setEditing] = useState<PageContent | null | 'new'>(null)
  const [saving, setSaving] = useState(false)

  async function load() {
    if (!supabase) {
      setLoading(false)
      return
    }
    setLoading(true)
    const { data } = await supabase.from('pages').select('*').order('created_at', { ascending: false })
    setPages((data ?? []).map(mapPageContent))
    setLoading(false)
  }

  useEffect(() => {
    load()
  }, [])

  async function handleSave(value: PageEditorValue) {
    if (!supabase) return
    setSaving(true)
    const row = toPageContentRow(value)

    if (editing && editing !== 'new') {
      await supabase.from('pages').update(row).eq('id', editing.id)
    } else {
      await supabase.from('pages').insert(row)
    }

    setSaving(false)
    setEditing(null)
    load()
  }

  async function handleDelete(id: string) {
    if (!supabase || !window.confirm('Delete this page?')) return
    await supabase.from('pages').delete().eq('id', id)
    load()
  }

  return (
    <div>
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-semibold">Custom pages</h1>
        <Button type="button" onClick={() => setEditing('new')} disabled={!isSupabaseConfigured}>
          <Plus size={16} aria-hidden />
          New page
        </Button>
      </div>

      {!isSupabaseConfigured && (
        <p className="mt-4 rounded-md border border-border bg-bg-elevated p-3 text-xs text-fg-muted">
          Connect Supabase to create and edit pages (see .env.example). Published pages are
          served at /page/&lt;slug&gt;.
        </p>
      )}

      {editing && (
        <div className="mt-6 rounded-lg border border-border p-6">
          <PageEditor initial={editing === 'new' ? undefined : editing} saving={saving} onSave={handleSave} />
          <button
            type="button"
            onClick={() => setEditing(null)}
            className="mt-4 text-sm text-fg-muted hover:text-fg cursor-pointer"
          >
            Cancel
          </button>
        </div>
      )}

      <ul className="mt-8 flex flex-col divide-y divide-border">
        {loading && <p className="text-fg-muted">Loading…</p>}
        {!loading && pages.length === 0 && <p className="text-fg-muted">No custom pages yet.</p>}
        {pages.map((page) => (
          <li key={page.id} className="flex items-center justify-between gap-4 py-4">
            <div>
              <p className="font-medium">{page.title}</p>
              <p className="text-sm text-fg-muted">
                /page/{page.slug} · {page.isPublished ? 'Published' : 'Draft'}
              </p>
            </div>
            <div className="flex gap-3">
              <button type="button" onClick={() => setEditing(page)} className="text-sm text-fg-muted hover:text-fg cursor-pointer">
                Edit
              </button>
              <button
                type="button"
                onClick={() => handleDelete(page.id)}
                className="text-sm text-fg-muted hover:text-accent cursor-pointer"
                aria-label={`Delete ${page.title}`}
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
