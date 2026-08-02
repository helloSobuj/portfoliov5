import { Section } from '@/components/ui/Section'
import { LinkButton } from '@/components/ui/Button'

export default function NotFoundPage() {
  return (
    <Section className="text-center">
      <p className="text-sm font-medium text-accent">404</p>
      <h1 className="mt-2 text-3xl font-semibold md:text-4xl">Page not found</h1>
      <p className="mt-3 text-fg-muted">The page you're looking for doesn't exist.</p>
      <div className="mt-8">
        <LinkButton to="/" variant="primary">
          Back home
        </LinkButton>
      </div>
    </Section>
  )
}
