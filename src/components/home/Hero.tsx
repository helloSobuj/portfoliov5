import { motion, type Variants } from 'framer-motion'
import { ArrowRight } from 'lucide-react'
import { site } from '@/data/site'
import { Section } from '@/components/ui/Section'
import { LinkButton } from '@/components/ui/Button'

const container: Variants = {
  hidden: {},
  show: { transition: { staggerChildren: 0.08 } },
}

const item: Variants = {
  hidden: { opacity: 0, y: 16 },
  show: { opacity: 1, y: 0, transition: { duration: 0.5, ease: [0.16, 1, 0.3, 1] } },
}

export function Hero() {
  return (
    <Section className="pt-20 md:pt-28" compact>
      <motion.div variants={container} initial="hidden" animate="show" className="max-w-3xl">
        <motion.p variants={item} className="text-sm font-medium text-accent">
          {site.role}
        </motion.p>
        <motion.h1 variants={item} className="mt-4 text-4xl font-semibold sm:text-5xl md:text-6xl">
          {site.tagline}
        </motion.h1>
        <motion.p variants={item} className="mt-6 max-w-xl text-lg text-fg-muted">
          Selected work, writing on animation and interface design, and a couple of ways to
          reach me below.
        </motion.p>
        <motion.div variants={item} className="mt-10 flex flex-wrap gap-4">
          <LinkButton to="/portfolio" variant="primary">
            View portfolio
            <ArrowRight size={16} aria-hidden />
          </LinkButton>
          <LinkButton to="/contact" variant="secondary">
            Get in touch
          </LinkButton>
        </motion.div>
      </motion.div>
    </Section>
  )
}
