import ReactMarkdown from 'react-markdown'
import remarkGfm from 'remark-gfm'
import { sanitizeHtml } from '@/lib/sanitize'
import type { PageContentFormat } from '@/types'

interface MarkdownEditorProps {
  value: string
  onChange: (value: string) => void
  format: PageContentFormat
  label?: string
}

/**
 * Split editor/preview pane for the HTML & Markdown page builder. HTML
 * input is sanitized with DOMPurify before it's ever rendered, in both
 * the live preview here and on the published page (see CustomPage.tsx).
 */
export function MarkdownEditor({ value, onChange, format, label = 'Content' }: MarkdownEditorProps) {
  return (
    <div className="grid gap-6 lg:grid-cols-2">
      <div>
        <label htmlFor="editor-body" className="text-sm font-medium text-fg-muted">
          {label} ({format === 'markdown' ? 'Markdown' : 'HTML'})
        </label>
        <textarea
          id="editor-body"
          value={value}
          onChange={(e) => onChange(e.target.value)}
          spellCheck={false}
          rows={20}
          className="mt-2 w-full rounded-md border border-border bg-bg-elevated p-4 font-mono text-sm text-fg focus:outline-none"
          placeholder={format === 'markdown' ? '## Heading\n\nYour content here…' : '<h2>Heading</h2>\n<p>Your content here…</p>'}
        />
      </div>

      <div>
        <p className="text-sm font-medium text-fg-muted">Preview</p>
        <div className="prose dark:prose-invert mt-2 max-w-none rounded-md border border-border bg-bg-elevated p-4">
          {format === 'markdown' ? (
            <ReactMarkdown remarkPlugins={[remarkGfm]}>{value}</ReactMarkdown>
          ) : (
            // eslint-disable-next-line react/no-danger
            <div dangerouslySetInnerHTML={{ __html: sanitizeHtml(value) }} />
          )}
        </div>
      </div>
    </div>
  )
}
