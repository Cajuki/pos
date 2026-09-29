import type { ReactNode } from 'react'
import { cn } from '../../lib/utils'

export function Card({ className, children }: { className?: string; children: ReactNode }) {
  return <div className={cn('rounded-2xl border border-graphite/10 bg-white/80 shadow-[0_14px_40px_rgba(37,37,37,0.04)]', className)}>{children}</div>
}
