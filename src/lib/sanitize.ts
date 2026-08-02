import DOMPurify from 'dompurify'

/** Strips scripts, event handlers, and other unsafe markup before rendering user-authored HTML. */
export function sanitizeHtml(dirty: string): string {
  return DOMPurify.sanitize(dirty, {
    USE_PROFILES: { html: true },
    ADD_ATTR: ['target', 'rel'],
  })
}
