import { Mail, MapPin } from 'lucide-react'
import { Section } from '@/components/ui/Section'
import { ContactForm } from '@/components/contact/ContactForm'
import { site } from '@/data/site'

const mapEmbedUrl = import.meta.env.VITE_CONTACT_MAP_EMBED_URL

export default function ContactPage() {
  return (
    <Section compact className="pt-16">
      <h1 className="text-3xl font-semibold md:text-4xl">Contact</h1>
      <p className="mt-3 max-w-xl text-fg-muted">
        Have a project, a question, or just want to say hi? Fill in the form and I'll get back
        to you.
      </p>

      <div className="mt-10 grid gap-12 lg:grid-cols-[1fr_320px]">
        <ContactForm />

        <aside className="flex flex-col gap-6">
          <div className="flex items-start gap-3">
            <Mail size={18} className="mt-0.5 text-accent" aria-hidden />
            <div>
              <p className="text-sm font-medium">Email</p>
              <a href={`mailto:${site.email}`} className="text-sm text-fg-muted hover:text-fg">
                {site.email}
              </a>
            </div>
          </div>
          <div className="flex items-start gap-3">
            <MapPin size={18} className="mt-0.5 text-accent" aria-hidden />
            <div>
              <p className="text-sm font-medium">Location</p>
              <p className="text-sm text-fg-muted">{site.location}</p>
            </div>
          </div>

          {mapEmbedUrl && (
            <iframe
              title="Location map"
              src={mapEmbedUrl}
              className="h-56 w-full rounded-md border border-border"
              loading="lazy"
              referrerPolicy="no-referrer-when-downgrade"
            />
          )}
        </aside>
      </div>
    </Section>
  )
}
