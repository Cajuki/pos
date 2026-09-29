import type { ReactNode } from 'react'
import { Card } from './Card'
import { ArrowUpRight, ArrowDownRight } from 'lucide-react'

interface StatCardProps {
  title: string
  value: string
  hint?: string
  trend?: string
  trendPositive?: boolean
  icon?: ReactNode
}

export function StatCard({ title, value, hint, trend, trendPositive = true, icon }: StatCardProps) {
  return (
    <Card className="relative overflow-hidden p-5 transition-shadow hover:shadow-md">
      <div className="flex items-start justify-between gap-3">
        <div>
          <p className="text-[11px] font-bold uppercase tracking-[0.2em] text-graphite/50">{title}</p>
          <p className="mt-2 text-3xl font-black tracking-tight text-graphite font-heading">{value}</p>
        </div>
        {icon && (
          <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-lime/20 text-graphite">
            {icon}
          </div>
        )}
      </div>

      <div className="mt-3 flex items-center justify-between text-xs">
        {hint && <span className="text-graphite/60">{hint}</span>}
        {trend && (
          <span
            className={`inline-flex items-center gap-0.5 rounded-full px-2 py-0.5 text-[11px] font-bold ${
              trendPositive
                ? 'bg-lime/30 text-graphite border border-lime-dark/30'
                : 'bg-danger/10 text-danger border border-danger/20'
            }`}
          >
            {trendPositive ? <ArrowUpRight size={13} /> : <ArrowDownRight size={13} />}
            {trend}
          </span>
        )}
      </div>
    </Card>
  )
}

