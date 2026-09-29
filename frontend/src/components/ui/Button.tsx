import type { ButtonHTMLAttributes, ReactNode } from 'react'
import { cn } from '../../lib/utils'

export type ButtonVariant = 'primary' | 'dark' | 'secondary' | 'outline' | 'ghost' | 'danger'
export type ButtonSize = 'sm' | 'md' | 'lg'

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant
  size?: ButtonSize
  icon?: ReactNode
}

export function Button({
  variant = 'primary',
  size = 'md',
  icon,
  className,
  children,
  ...props
}: ButtonProps) {
  const variants: Record<ButtonVariant, string> = {
    primary: 'bg-lime text-graphite hover:bg-[#a8e83b] active:scale-[0.98] shadow-sm font-bold',
    dark: 'bg-graphite text-champagne hover:bg-graphite-light active:scale-[0.98] shadow-sm font-semibold',
    secondary: 'bg-white text-graphite border border-graphite/15 hover:bg-champagne-light/70 active:scale-[0.98]',
    outline: 'border border-graphite/20 bg-transparent text-graphite hover:bg-graphite/5',
    ghost: 'bg-transparent text-graphite hover:bg-graphite/5',
    danger: 'bg-danger text-white hover:bg-danger/90',
  }

  const sizes: Record<ButtonSize, string> = {
    sm: 'h-8 px-3 text-xs rounded-lg gap-1.5',
    md: 'h-10 px-4 text-sm rounded-xl gap-2',
    lg: 'h-12 px-5 text-base rounded-xl gap-2.5',
  }

  return (
    <button
      className={cn(
        'inline-flex items-center justify-center font-medium transition-all duration-150 cursor-pointer disabled:cursor-not-allowed disabled:opacity-50',
        variants[variant],
        sizes[size],
        className,
      )}
      {...props}
    >
      {icon}
      {children}
    </button>
  )
}

