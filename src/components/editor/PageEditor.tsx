import { useState } from 'react'
import { MarkdownEditor } from './MarkdownEditor'
import { Button } from '@/components/ui/Button'
import { cn } from '@/lib/cn'
import type { PageContent, PageContentFormat } from '@/types'

export interface PageEditorValue {
  title: string
  slug: string
  format: PageContentFormat
  body: string
  isPublished: boolean
}

interface PageEditorProps {
  initial?: Partial<PageContent>
  saving?: boolean
  onSave: (value: PageEditorValue) => void
}

export function PageEditor({ initial, saving = false, onSave }: PageEditorProps) {
  const [title, setTitle] = useState(initial?.title ?? '')
  const [slug, setSlug] = useState(initial?.slug ?? '')
  const [format, setFormat] = useState<PageContentFormat>(initial?.format ?? 'markdown')
  const [body, setBody] = useState(initial?.body ?? '')
  const [isPublished, setIsPublished] = useState(initial?.isPublished ?? false)

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault()
        onSave({ title, slug, format, body, isPublished })
      }}
      className="flex flex-col gap-6"
    >
      <div className="grid gap-6 sm:grid-cols-2">
        <div>
          <label htmlFor="page-title" className="text-sm font-medium text-fg-muted">
            Title
          </label>
          <input
            id="page-title"
            required
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            className="mt-2 w-full rounded-md border border-border bg-bg-elevated px-3 py-2 text-sm focus:outline-none"
          />
        </div>
        <div>
          <label htmlFor="page-slug" className="text-sm font-medium text-fg-muted">
            Slug
          </label>
          <input
            id="page-slug"
            required
            pattern="[a-z0-9-]+"
            title="Lowercase letters, numbers, and hyphens only"
            value={slug}
            onChange={(e) => setSlug(e.target.value)}
            className="mt-2 w-full rounded-md border border-border bg-bg-elevated px-3 py-2 text-sm focus:outline-none"
          />
        </div>
      </div>

      <div className="flex items-center gap-4">
        <div role="radiogroup" aria-label="Content format" className="flex gap-1 rounded-md border border-border p-1">
          {(['markdown', 'html'] as const).map((f) => (
            <button
              key={f}
              type="button"
              role="radio"
              aria-checked={format === f}
              onClick={() => setFormat(f)}
              className={cn(
                'rounded px-3 py-1.5 text-sm capitalize cursor-pointer',
                format === f ? 'bg-accent text-accent-fg' : 'text-fg-muted',
              )}
            >
              {f}
            </button>
          ))}
        </div>

        <label className="ml-auto flex items-center gap-2 text-sm text-fg-muted">
          <input
            type="checkbox"
            checked={isPublished}
            onChange={(e) => setIsPublished(e.target.checked)}
            className="h-4 w-4 rounded border-border"
          />
          Published
        </label>
      </div>

      <MarkdownEditor value={body} onChange={setBody} format={format} />

      <Button type="submit" disabled={saving} className="self-start">
        {saving ? 'Saving…' : 'Save page'}
      </Button>
    </form>
  )
}
