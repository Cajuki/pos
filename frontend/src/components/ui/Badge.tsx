import type { ReactNode } from 'react'
import { cn } from '../../lib/utils'

export type BadgeVariant = 'default' | 'success' | 'warning' | 'danger' | 'lime' | 'graphite'

interface BadgeProps {
  children: ReactNode
  variant?: BadgeVariant
  className?: string
}

export function Badge({ children, variant = 'default', className }: BadgeProps) {
  const variants: Record<BadgeVariant, string> = {
    default: 'border-graphite/10 bg-champagne-light text-graphite/80',
    success: 'border-success/20 bg-success/10 text-success',
    warning: 'border-warning/20 bg-warning/10 text-warning',
    danger: 'border-danger/20 bg-danger/10 text-danger',
    lime: 'border-lime-dark/30 bg-lime text-graphite font-bold',
    graphite: 'border-graphite bg-graphite text-champagne',
  }

  return (
    <span
      className={cn(
        'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-[11px] font-semibold tracking-wide transition-colors',
        variants[variant],
        className,
      )}
    >
      {children}
    </span>
  )
}

