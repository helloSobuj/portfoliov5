import { forwardRef } from 'react'
import type { ButtonHTMLAttributes, AnchorHTMLAttributes } from 'react'
import { Link, type LinkProps } from 'react-router-dom'
import { cn } from '@/lib/cn'

const base =
  'inline-flex items-center justify-center gap-2 rounded-md px-6 py-3 text-sm font-medium transition-colors cursor-pointer disabled:cursor-not-allowed disabled:opacity-50'

const variants = {
  primary: 'bg-accent text-accent-fg hover:opacity-90',
  secondary: 'border border-border text-fg hover:bg-bg-elevated',
  ghost: 'text-fg-muted hover:text-fg',
}

type Variant = keyof typeof variants

export const Button = forwardRef<HTMLButtonElement, ButtonHTMLAttributes<HTMLButtonElement> & { variant?: Variant }>(
  ({ className, variant = 'primary', ...props }, ref) => (
    <button ref={ref} className={cn(base, variants[variant], className)} {...props} />
  ),
)
Button.displayName = 'Button'

export function LinkButton({
  className,
  variant = 'primary',
  ...props
}: LinkProps & AnchorHTMLAttributes<HTMLAnchorElement> & { variant?: Variant }) {
  return <Link className={cn(base, variants[variant], className)} {...props} />
}
