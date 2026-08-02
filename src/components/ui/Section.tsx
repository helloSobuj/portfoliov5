import type { ReactNode } from 'react'
import { cn } from '@/lib/cn'
import { Container } from './Container'

interface SectionProps {
  children: ReactNode
  className?: string
  id?: string
  compact?: boolean
}

/** Standard section rhythm: 112px (py-28) between sections, 64px (py-16) for compact ones. */
export function Section({ children, className, id, compact = false }: SectionProps) {
  return (
    <section id={id} className={cn(compact ? 'py-16' : 'py-28', className)}>
      <Container>{children}</Container>
    </section>
  )
}
