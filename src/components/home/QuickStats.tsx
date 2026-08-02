import { motion } from 'framer-motion'
import { quickStats } from '@/data/mock'
import { Section } from '@/components/ui/Section'

export function QuickStats() {
  return (
    <Section compact className="border-y border-border">
      <dl className="grid grid-cols-2 gap-8 md:grid-cols-4">
        {quickStats.map((stat, i) => (
          <motion.div
            key={stat.label}
            initial={{ opacity: 0, y: 12 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true, margin: '-80px' }}
            transition={{ duration: 0.4, delay: i * 0.08 }}
          >
            <dt className="text-3xl font-semibold text-fg md:text-4xl">{stat.value}</dt>
            <dd className="mt-1 text-sm text-fg-muted">{stat.label}</dd>
          </motion.div>
        ))}
      </dl>
    </Section>
  )
}
