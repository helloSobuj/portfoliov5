import { useState, type FormEvent } from 'react'
import { z } from 'zod'
import { CheckCircle2, Loader2 } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { supabase } from '@/lib/supabase'

const schema = z.object({
  name: z.string().trim().min(1, 'Name is required'),
  email: z.string().trim().email('Enter a valid email'),
  subject: z.string().trim().min(1, 'Subject is required'),
  message: z.string().trim().min(10, 'Message should be at least 10 characters'),
})

type FieldErrors = Partial<Record<keyof z.infer<typeof schema>, string>>

export function ContactForm() {
  const [values, setValues] = useState({ name: '', email: '', subject: '', message: '' })
  const [errors, setErrors] = useState<FieldErrors>({})
  const [status, setStatus] = useState<'idle' | 'submitting' | 'success' | 'error'>('idle')

  async function handleSubmit(e: FormEvent) {
    e.preventDefault()
    const result = schema.safeParse(values)
    if (!result.success) {
      const fieldErrors: FieldErrors = {}
      for (const issue of result.error.issues) {
        const key = issue.path[0] as keyof FieldErrors
        fieldErrors[key] = issue.message
      }
      setErrors(fieldErrors)
      return
    }
    setErrors({})
    setStatus('submitting')

    if (!supabase) {
      // No backend configured yet — simulate success so the form is demoable.
      await new Promise((r) => setTimeout(r, 400))
      setStatus('success')
      return
    }

    const { error } = await supabase.from('messages').insert(result.data)
    setStatus(error ? 'error' : 'success')
  }

  if (status === 'success') {
    return (
      <div className="flex items-center gap-3 rounded-md border border-border bg-bg-elevated p-6 text-fg">
        <CheckCircle2 className="text-accent" size={20} aria-hidden />
        <p>Thanks — your message is on its way. I'll reply within a couple of days.</p>
      </div>
    )
  }

  return (
    <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-5">
      <div className="grid gap-5 sm:grid-cols-2">
        <Field
          label="Name"
          id="name"
          value={values.name}
          error={errors.name}
          onChange={(v) => setValues((s) => ({ ...s, name: v }))}
        />
        <Field
          label="Email"
          id="email"
          type="email"
          value={values.email}
          error={errors.email}
          onChange={(v) => setValues((s) => ({ ...s, email: v }))}
        />
      </div>

      <Field
        label="Subject"
        id="subject"
        value={values.subject}
        error={errors.subject}
        onChange={(v) => setValues((s) => ({ ...s, subject: v }))}
      />

      <div>
        <label htmlFor="message" className="text-sm font-medium text-fg-muted">
          Message
        </label>
        <textarea
          id="message"
          rows={5}
          value={values.message}
          onChange={(e) => setValues((s) => ({ ...s, message: e.target.value }))}
          aria-invalid={!!errors.message}
          aria-describedby={errors.message ? 'message-error' : undefined}
          className="mt-2 w-full rounded-md border border-border bg-bg-elevated px-3 py-2 text-sm focus:outline-none"
        />
        {errors.message && (
          <p id="message-error" className="mt-1 text-xs text-accent">
            {errors.message}
          </p>
        )}
      </div>

      {status === 'error' && (
        <p role="alert" className="text-sm text-accent">
          Something went wrong sending your message — please try again.
        </p>
      )}

      <Button type="submit" disabled={status === 'submitting'} className="self-start">
        {status === 'submitting' ? (
          <>
            <Loader2 className="animate-spin" size={16} aria-hidden />
            Sending…
          </>
        ) : (
          'Send message'
        )}
      </Button>
    </form>
  )
}

function Field({
  label,
  id,
  value,
  error,
  onChange,
  type = 'text',
}: {
  label: string
  id: string
  value: string
  error?: string
  onChange: (v: string) => void
  type?: string
}) {
  return (
    <div>
      <label htmlFor={id} className="text-sm font-medium text-fg-muted">
        {label}
      </label>
      <input
        id={id}
        type={type}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        aria-invalid={!!error}
        aria-describedby={error ? `${id}-error` : undefined}
        className="mt-2 w-full rounded-md border border-border bg-bg-elevated px-3 py-2 text-sm focus:outline-none"
      />
      {error && (
        <p id={`${id}-error`} className="mt-1 text-xs text-accent">
          {error}
        </p>
      )}
    </div>
  )
}
