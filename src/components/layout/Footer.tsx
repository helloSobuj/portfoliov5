import { site } from '@/data/site'
import { Container } from '@/components/ui/Container'
import { GithubIcon, LinkedinIcon, XIcon } from '@/components/icons/BrandIcons'

export function Footer() {
  return (
    <footer className="border-t border-border py-12">
      <Container className="flex flex-col items-center gap-6 text-center md:flex-row md:justify-between md:text-left">
        <div>
          <p className="font-heading font-semibold">{site.name}</p>
          <p className="text-sm text-fg-muted">{site.tagline}</p>
        </div>

        <div className="flex items-center gap-5">
          <a href={site.social.github} target="_blank" rel="noreferrer" aria-label="GitHub" className="text-fg-muted hover:text-fg">
            <GithubIcon width={18} height={18} />
          </a>
          <a href={site.social.linkedin} target="_blank" rel="noreferrer" aria-label="LinkedIn" className="text-fg-muted hover:text-fg">
            <LinkedinIcon width={18} height={18} />
          </a>
          <a href={site.social.x} target="_blank" rel="noreferrer" aria-label="X" className="text-fg-muted hover:text-fg">
            <XIcon width={18} height={18} />
          </a>
        </div>

        <p className="text-xs text-fg-muted">© {new Date().getFullYear()} {site.name}. All rights reserved.</p>
      </Container>
    </footer>
  )
}
