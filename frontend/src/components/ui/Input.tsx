import type { InputHTMLAttributes } from 'react'
import { cn } from '../../lib/utils'

interface InputProps extends InputHTMLAttributes<HTMLInputElement> {
  label?: string
}

export function Input({ label, className, ...props }: InputProps) {
  const field = (
    <input
      className={cn(
        'w-full rounded-xl border border-graphite/10 bg-white px-4 py-3 text-sm text-graphite outline-none transition focus:border-lime focus:ring-2 focus:ring-lime/30',
        className,
      )}
      {...props}
    />
  )

  if (!label) return field

  return (
    <label className="block text-sm font-medium text-graphite">
      <span className="mb-2 block">{label}</span>
      {field}
    </label>
  )
}
