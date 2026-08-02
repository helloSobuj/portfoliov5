import { motion } from 'framer-motion'
import { Section } from '@/components/ui/Section'
import { LinkButton } from '@/components/ui/Button'

export function CTA() {
  return (
    <Section className="border-t border-border" compact>
      <motion.div
        initial={{ opacity: 0, y: 12 }}
        whileInView={{ opacity: 1, y: 0 }}
        viewport={{ once: true, margin: '-80px' }}
        transition={{ duration: 0.5 }}
        className="flex flex-col items-center gap-6 rounded-xl bg-bg-elevated px-8 py-16 text-center"
      >
        <h2 className="text-2xl font-semibold md:text-3xl">Have a project in mind?</h2>
        <p className="max-w-md text-fg-muted">
          I take on a handful of new projects each quarter — reach out and let's see if it's a
          fit.
        </p>
        <LinkButton to="/contact" variant="primary">
          Start a conversation
        </LinkButton>
      </motion.div>
    </Section>
  )
}
