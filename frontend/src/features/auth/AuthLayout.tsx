import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { ShieldCheck, Sparkles } from 'lucide-react'

export default function AuthLayout({ children }: { children: ReactNode }) {
  return (
    <div className="min-h-screen bg-[radial-gradient(ellipse_at_top,_#fdfbf7,_#f6efe2_45%,_#efe5d1_100%)] p-4 sm:p-6 lg:p-10 flex items-center justify-center text-graphite antialiased">
      <div className="w-full max-w-5xl overflow-hidden rounded-[28px] border border-graphite/10 bg-white/70 shadow-[0_24px_60px_rgba(37,37,37,0.08)] backdrop-blur-md">
        <div className="grid min-h-[640px] lg:grid-cols-[1.05fr_1.15fr]">
          {/* Left Hero Branding Column */}
          <div className="hidden bg-graphite p-10 text-champagne lg:flex lg:flex-col lg:justify-between relative overflow-hidden">
            <div className="absolute -right-20 -bottom-20 w-80 h-80 rounded-full bg-lime/10 blur-3xl pointer-events-none" />

            <div>
              <Link to="/" className="mb-10 inline-flex items-center gap-3 group">
                <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-lime text-graphite font-black text-xl shadow-md group-hover:scale-105 transition-transform font-heading">
                  P
                </div>
                <div className="flex flex-col">
                  <span className="text-2xl font-extrabold tracking-tight text-champagne font-heading">
                    poss<span className="text-lime">.</span>
                  </span>
                  <span className="text-[10px] uppercase tracking-[0.25em] text-champagne/50 font-bold -mt-1">
                    Commercial POS
                  </span>
                </div>
              </Link>

              <div className="space-y-4">
                <span className="inline-flex items-center gap-1.5 rounded-full bg-lime/20 border border-lime/30 px-3 py-1 text-[11px] font-bold text-lime uppercase tracking-wider">
                  <Sparkles size={12} />
                  Enterprise Grade
                </span>
                <h1 className="text-3xl font-extrabold leading-tight font-heading">
                  Point of Sale and Inventory Ledger for modern retail.
                </h1>
                <p className="text-sm text-champagne/70 leading-relaxed max-w-sm">
                  Full transactional control, double-entry stock ledger, M-Pesa payments, and analytics built for Kenyan businesses.
                </p>
              </div>

              <div className="mt-8 space-y-2.5">
                {[
                  'Instant cashier checkout with barcode search',
                  'Audited stock movements on every transaction',
                  'Customer credit accounts & debt tracking',
                  'Decoupled architecture ready for SellFlux',
                ].map((feature) => (
                  <div key={feature} className="flex items-center gap-2.5 text-xs text-champagne/80">
                    <div className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-lime/20 text-lime">
                      <ShieldCheck size={12} />
                    </div>
                    <span>{feature}</span>
                  </div>
                ))}
              </div>
            </div>

            <div className="flex items-center justify-between rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-xs text-champagne/70">
              <span>Demo Retail Ltd · Nairobi</span>
              <span className="rounded-full bg-lime px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-graphite font-heading">
                KES Currency
              </span>
            </div>
          </div>

          {/* Right Form Column */}
          <div className="flex flex-col justify-center p-6 sm:p-10 lg:p-12 bg-white/50">
            {children}
          </div>
        </div>
      </div>
    </div>
  )
}

