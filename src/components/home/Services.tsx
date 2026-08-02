import { motion } from 'framer-motion'
import { Code2, PenTool, Compass, type LucideIcon } from 'lucide-react'
import { services } from '@/data/mock'
import { Section } from '@/components/ui/Section'

const ICONS: Record<string, LucideIcon> = { Code2, PenTool, Compass }

export function Services() {
  return (
    <Section className="border-t border-border" compact>
      <h2 className="text-2xl font-semibold md:text-3xl">What I do</h2>
      <div className="mt-10 grid gap-8 md:grid-cols-3">
        {services.map((service, i) => {
          const Icon = ICONS[service.icon] ?? Code2
          return (
            <motion.div
              key={service.title}
              initial={{ opacity: 0, y: 12 }}
              whileInView={{ opacity: 1, y: 0 }}
              viewport={{ once: true, margin: '-80px' }}
              transition={{ duration: 0.4, delay: i * 0.08 }}
              className="rounded-lg border border-border p-6"
            >
              <Icon size={22} className="text-accent" aria-hidden />
              <h3 className="mt-4 font-medium">{service.title}</h3>
              <p className="mt-2 text-sm text-fg-muted">{service.description}</p>
            </motion.div>
          )
        })}
      </div>
    </Section>
  )
}
