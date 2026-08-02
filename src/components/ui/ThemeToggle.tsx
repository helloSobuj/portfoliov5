import { motion } from 'framer-motion'
import { Moon, Sun } from 'lucide-react'
import { useTheme } from '@/context/ThemeContext'

export function ThemeToggle() {
  const { theme, toggleTheme } = useTheme()
  const isNight = theme === 'night'

  return (
    <button
      type="button"
      onClick={toggleTheme}
      aria-label={isNight ? 'Switch to day theme' : 'Switch to night theme'}
      aria-pressed={isNight}
      className="relative flex h-9 w-16 items-center rounded-full border border-border bg-bg-elevated px-1 transition-colors cursor-pointer"
    >
      <motion.span
        layout
        transition={{ type: 'spring', stiffness: 500, damping: 30 }}
        className="flex h-7 w-7 items-center justify-center rounded-full bg-accent text-accent-fg"
        style={{ marginLeft: isNight ? 'auto' : 0 }}
      >
        {isNight ? <Moon size={15} aria-hidden /> : <Sun size={15} aria-hidden />}
      </motion.span>
      <span className="sr-only">Toggle day/night theme</span>
    </button>
  )
}
