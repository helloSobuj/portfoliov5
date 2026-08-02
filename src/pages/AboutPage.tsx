import { motion } from 'framer-motion'
import { Section } from '@/components/ui/Section'
import { LinkButton } from '@/components/ui/Button'
import { site } from '@/data/site'

const experience = [
  { period: '2023 — Now', role: 'Independent Product Engineer' },
  { period: '2021 — 2023', role: 'Senior Frontend Engineer, Some Startup' },
  { period: '2019 — 2021', role: 'Frontend Engineer, Another Company' },
]

export default function AboutPage() {
  return (
    <Section compact className="pt-16">
      <div className="grid gap-16 md:grid-cols-[280px_1fr]">
        <motion.div
          initial={{ opacity: 0, y: 12 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.5 }}
        >
          <div className="aspect-square overflow-hidden rounded-lg bg-bg-elevated">
            <img
              src="https://images.unsplash.com/photo-1531891437562-4301cf35b7e4?w=800&q=80"
              alt="Portrait placeholder"
              className="h-full w-full object-cover"
            />
          </div>
          <LinkButton to="/contact" variant="secondary" className="mt-6 w-full">
            Get in touch
          </LinkButton>
        </motion.div>

        <motion.div
          initial={{ opacity: 0, y: 12 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.5, delay: 0.1 }}
        >
          <h1 className="text-3xl font-semibold md:text-4xl">About {site.name}</h1>
          <div className="mt-6 flex flex-col gap-4 text-fg-muted">
            <p>
              I'm a {site.role.toLowerCase()} based in {site.location}. I've spent the last
              several years building interfaces that balance craft with speed — motion that
              earns its place, and code that's easy to hand off.
            </p>
            <p>
              Outside of client work, I write about animation and design systems on the blog,
              and maintain a handful of open-source UI libraries.
            </p>
          </div>

          <h2 className="mt-10 text-lg font-medium">Experience</h2>
          <ul className="mt-4 flex flex-col gap-3">
            {experience.map((role) => (
              <li key={role.role} className="flex gap-4 text-sm">
                <span className="w-32 shrink-0 text-fg-muted">{role.period}</span>
                <span>{role.role}</span>
              </li>
            ))}
          </ul>
        </motion.div>
      </div>
    </Section>
  )
}
