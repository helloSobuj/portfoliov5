import { useLayoutEffect, useRef, type ReactNode } from 'react'
import { useLocation } from 'react-router-dom'
import gsap from 'gsap'

/**
 * GSAP-driven page transition: fades/lifts the outlet content on every
 * route change. Framer Motion handles micro-interactions inside pages;
 * GSAP owns this top-level, route-level transition per the brief.
 */
export function PageTransition({ children }: { children: ReactNode }) {
  const { pathname } = useLocation()
  const containerRef = useRef<HTMLDivElement>(null)

  useLayoutEffect(() => {
    const el = containerRef.current
    if (!el) return

    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches
    if (prefersReducedMotion) return

    const ctx = gsap.context(() => {
      gsap.fromTo(
        el,
        { autoAlpha: 0, y: 16 },
        { autoAlpha: 1, y: 0, duration: 0.5, ease: 'power3.out' },
      )
    }, containerRef)

    window.scrollTo({ top: 0, behavior: 'instant' as ScrollBehavior })

    return () => ctx.revert()
  }, [pathname])

  return (
    <div ref={containerRef} key={pathname}>
      {children}
    </div>
  )
}
