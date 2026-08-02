import { motion } from 'framer-motion'
import { Section } from '@/components/ui/Section'
import { LinkButton } from '@/components/ui/Button'

export function AboutTeaser() {
  return (
    <Section>
      <div className="grid gap-12 md:grid-cols-2 md:items-center">
        <motion.div
          initial={{ opacity: 0, x: -16 }}
          whileInView={{ opacity: 1, x: 0 }}
          viewport={{ once: true, margin: '-80px' }}
          transition={{ duration: 0.5 }}
          className="aspect-4/3 overflow-hidden rounded-lg bg-bg-elevated"
        >
          <img
            src="https://images.unsplash.com/photo-1531891437562-4301cf35b7e4?w=1200&q=80"
            alt="Portrait placeholder"
            className="h-full w-full object-cover"
            loading="lazy"
          />
        </motion.div>

        <motion.div
          initial={{ opacity: 0, x: 16 }}
          whileInView={{ opacity: 1, x: 0 }}
          viewport={{ once: true, margin: '-80px' }}
          transition={{ duration: 0.5, delay: 0.1 }}
        >
          <h2 className="text-2xl font-semibold md:text-3xl">A bit about me</h2>
          <p className="mt-4 text-fg-muted">
            I'm a product engineer who spends equal time in code editors and design tools. I
            care about interfaces that feel fast, accessible, and considered — down to the
            easing curve on a hover state.
          </p>
          <div className="mt-6">
            <LinkButton to="/about" variant="secondary">
              More about me
            </LinkButton>
          </div>
        </motion.div>
      </div>
    </Section>
  )
}
