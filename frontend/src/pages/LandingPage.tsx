import { Link, useNavigate } from 'react-router-dom'
import {
  ArrowRight,
  BarChart3,
  Boxes,
  CheckCircle2,
  CreditCard,
  LayoutDashboard,
  LogOut,
  ShieldCheck,
  ShoppingCart,
  Sparkles,
  UsersRound,
} from 'lucide-react'
import { Badge } from '../components/ui/Badge'
import { Card } from '../components/ui/Card'

const currentYear = new Date().getFullYear()

export default function LandingPage() {
  const navigate = useNavigate()
  const token = localStorage.getItem('pos_token')

  function handleLogout() {
    localStorage.removeItem('pos_token')
    localStorage.removeItem('pos_user')
    localStorage.removeItem('pos_business_id')
    localStorage.removeItem('pos_business_name')
    localStorage.removeItem('pos_user_role')
    window.dispatchEvent(new Event('pos-auth-changed'))
    navigate('/')
  }

  return (
    <div className="min-h-screen bg-[radial-gradient(ellipse_at_top,_#fdfbf7,_#f6efe2_45%,_#efe5d1_100%)] text-graphite antialiased selection:bg-lime selection:text-graphite">
      {/* Navigation Header */}
      <header className="sticky top-0 z-40 border-b border-graphite/10 bg-white/80 backdrop-blur-md">
        <div className="mx-auto flex max-w-7xl items-center justify-between px-6 py-4 lg:px-12">
          <Link to="/" className="flex items-center gap-3 group">
            <div className="flex h-10 w-10 items-center justify-center rounded-2xl bg-graphite text-lime font-black tracking-tighter shadow-md group-hover:scale-105 transition-transform">
              <span className="text-xl font-heading">P</span>
            </div>
            <div className="flex flex-col">
              <span className="text-xl font-extrabold tracking-tight text-graphite font-heading">
                poss<span className="text-lime-dark">.</span>
              </span>
              <span className="text-[9px] uppercase tracking-[0.25em] text-graphite/50 font-bold -mt-1">
                Commercial POS
              </span>
            </div>
          </Link>

          <nav className="hidden items-center gap-8 text-sm font-semibold text-graphite/70 md:flex">
            <a href="#features" className="hover:text-graphite transition">Features</a>
            <a href="#pos-tour" className="hover:text-graphite transition">POS Tour</a>
            <a href="#workflow" className="hover:text-graphite transition">Workflow</a>
          </nav>

          <div className="flex items-center gap-3">
            {token ? (
              <>
                <Link
                  to="/dashboard"
                  className="flex items-center gap-2 rounded-xl bg-lime px-4 py-2.5 text-sm font-bold text-graphite shadow-sm hover:bg-lime-light transition"
                >
                  <LayoutDashboard size={16} />
                  Dashboard
                </Link>
                <button
                  type="button"
                  onClick={handleLogout}
                  title="Sign out"
                  className="flex h-10 w-10 items-center justify-center rounded-xl border border-graphite/15 bg-white text-graphite/70 hover:bg-champagne-light transition cursor-pointer"
                >
                  <LogOut size={16} />
                </button>
              </>
            ) : (
              <>
                <Link
                  to="/login"
                  className="rounded-xl px-4 py-2 text-sm font-bold text-graphite/80 hover:text-graphite transition"
                >
                  Sign in
                </Link>
                <Link
                  to="/register"
                  className="rounded-xl bg-lime px-4 py-2.5 text-sm font-bold text-graphite shadow-sm hover:bg-[#a7e839] transition"
                >
                  Create account
                </Link>
              </>
            )}
          </div>
        </div>
      </header>

      <main>
        {/* Hero Section */}
        <section className="relative mx-auto max-w-7xl px-6 pt-12 pb-20 lg:px-12 lg:pt-16">
          <div className="grid gap-12 lg:grid-cols-[1.1fr_0.9fr] lg:items-center">
            <div>
              <div className="flex flex-wrap items-center gap-2">
                <Badge variant="lime" className="px-3 py-1">
                  <Sparkles size={13} className="text-graphite" />
                  Kenyan Retail Engine
                </Badge>
                <Badge variant="default" className="bg-white/80 border-graphite/10">
                  PostgreSQL 16 + Laravel Sanctum
                </Badge>
              </div>

              <h1 className="mt-6 text-4xl font-extrabold tracking-tight text-graphite sm:text-5xl lg:text-6xl font-heading leading-[1.08]">
                Run your store with <span className="underline decoration-lime decoration-4 underline-offset-4">smart sales</span> and tighter inventory control.
              </h1>

              <p className="mt-6 max-w-xl text-lg text-graphite/75 leading-relaxed">
                The modern point-of-sale platform built for small and medium-sized businesses. Fast cashier checkout, audited stock ledger, customer store credit, M-Pesa settlements, and analytics.
              </p>

              <div className="mt-8 flex flex-wrap items-center gap-4">
                {token ? (
                  <Link
                    to="/dashboard"
                    className="inline-flex h-12 items-center gap-2.5 rounded-xl bg-graphite px-6 text-sm font-bold text-champagne shadow-lg hover:bg-graphite-light transition"
                  >
                    Go to Business Dashboard <ArrowRight size={16} />
                  </Link>
                ) : (
                  <>
                    <Link
                      to="/register"
                      className="inline-flex h-12 items-center gap-2.5 rounded-xl bg-lime px-6 text-sm font-bold text-graphite shadow-md hover:bg-[#a8e83b] transition"
                    >
                      Create Free Workspace <ArrowRight size={16} />
                    </Link>
                    <Link
                      to="/login"
                      className="inline-flex h-12 items-center gap-2 rounded-xl border border-graphite/20 bg-white px-5 text-sm font-bold text-graphite hover:bg-champagne-light transition shadow-sm"
                    >
                      Sign in to workspace
                    </Link>
                  </>
                )}
              </div>

              {/* Trust Indicators */}
              <div className="mt-10 grid grid-cols-3 gap-4 border-t border-graphite/10 pt-6">
                <div>
                  <p className="text-2xl font-black text-graphite font-heading">KES 48M+</p>
                  <p className="text-xs text-graphite/60 font-medium">Monthly Retail Volume</p>
                </div>
                <div>
                  <p className="text-2xl font-black text-graphite font-heading">&lt; 50ms</p>
                  <p className="text-xs text-graphite/60 font-medium">Checkout Speed</p>
                </div>
                <div>
                  <p className="text-2xl font-black text-graphite font-heading">100%</p>
                  <p className="text-xs text-graphite/60 font-medium">Stock Audit Accuracy</p>
                </div>
              </div>
            </div>

            {/* Interactive POS Mock Preview */}
            <div id="pos-tour" className="relative">
              <div className="absolute -inset-1 rounded-[32px] bg-gradient-to-r from-lime via-champagne-dark to-lime/40 opacity-70 blur-xl" />
              <Card className="relative overflow-hidden border-2 border-graphite/15 bg-white p-6 shadow-2xl">
                <div className="flex items-center justify-between border-b border-graphite/10 pb-4">
                  <div className="flex items-center gap-2.5">
                    <span className="flex h-3 w-3 rounded-full bg-danger/80" />
                    <span className="flex h-3 w-3 rounded-full bg-warning/80" />
                    <span className="flex h-3 w-3 rounded-full bg-lime" />
                    <span className="ml-2 text-xs font-bold text-graphite/70 uppercase tracking-widest font-heading">
                      POS Register · Demo Retail Ltd
                    </span>
                  </div>
                  <Badge variant="lime" className="text-[10px]">
                    Register Open
                  </Badge>
                </div>

                {/* Simulated POS Screen */}
                <div className="mt-4 grid grid-cols-[1.2fr_1fr] gap-4">
                  {/* Left: Product Selection */}
                  <div className="space-y-2.5">
                    <p className="text-[11px] font-bold text-graphite/50 uppercase tracking-wider">Fast Items</p>
                    {[
                      { name: 'Kericho Gold Black Tea', sku: 'TEA-KG-250', price: '220.00', stock: '85 in stock' },
                      { name: 'Unga wa Dola Maize 2kg', sku: 'MZ-DOLA-2KG', price: '165.00', stock: '140 in stock' },
                      { name: 'Brookside Fresh Milk 500ml', sku: 'MLK-BK-500', price: '65.00', stock: '110 in stock' },
                    ].map((item) => (
                      <div
                        key={item.sku}
                        className="rounded-xl border border-graphite/10 bg-champagne-light/50 p-2.5 hover:border-lime transition"
                      >
                        <div className="flex justify-between items-start">
                          <p className="text-xs font-bold text-graphite">{item.name}</p>
                          <span className="text-xs font-black text-graphite font-heading">KES {item.price}</span>
                        </div>
                        <p className="text-[10px] text-graphite/50 mt-1">{item.stock}</p>
                      </div>
                    ))}
                  </div>

                  {/* Right: Cart & Payment */}
                  <div className="flex flex-col justify-between rounded-xl bg-graphite p-3.5 text-champagne">
                    <div>
                      <div className="flex items-center justify-between border-b border-white/10 pb-2">
                        <span className="text-xs font-bold uppercase tracking-wider text-lime">Current Cart</span>
                        <span className="text-[10px] text-champagne/60">3 items</span>
                      </div>
                      <div className="mt-2.5 space-y-1.5 text-xs text-champagne/80">
                        <div className="flex justify-between">
                          <span>1 × Kericho Tea</span>
                          <span>KES 220</span>
                        </div>
                        <div className="flex justify-between">
                          <span>2 × Dola Maize</span>
                          <span>KES 330</span>
                        </div>
                        <div className="flex justify-between">
                          <span>1 × Brookside Milk</span>
                          <span>KES 65</span>
                        </div>
                      </div>
                    </div>

                    <div className="mt-4 border-t border-white/10 pt-3">
                      <div className="flex justify-between text-xs text-champagne/70">
                        <span>16% VAT</span>
                        <span>KES 84.80</span>
                      </div>
                      <div className="mt-1 flex justify-between text-base font-black text-lime font-heading">
                        <span>Total</span>
                        <span>KES 615.00</span>
                      </div>
                      <div className="mt-3 grid grid-cols-2 gap-2 text-center text-[10px] font-bold">
                        <span className="rounded-lg bg-white/10 py-1.5 text-champagne">Cash Paid</span>
                        <span className="rounded-lg bg-lime py-1.5 text-graphite">M-Pesa STK</span>
                      </div>
                    </div>
                  </div>
                </div>

                <div className="mt-4 rounded-xl bg-lime/10 border border-lime/30 p-3 text-xs text-graphite flex items-center justify-between">
                  <span className="font-semibold flex items-center gap-1.5">
                    <CheckCircle2 size={15} className="text-lime-dark" />
                    Sale locks stock in PostgreSQL transaction
                  </span>
                  <span className="font-bold text-[11px] text-graphite/70">Audit Logged</span>
                </div>
              </Card>
            </div>
          </div>
        </section>

        {/* Feature Highlights Grid */}
        <section id="features" className="border-y border-graphite/10 bg-white/60 py-20">
          <div className="mx-auto max-w-7xl px-6 lg:px-12">
            <div className="max-w-2xl">
              <p className="text-xs font-bold uppercase tracking-[0.25em] text-graphite/50">Comprehensive Suite</p>
              <h2 className="mt-2 text-3xl font-extrabold tracking-tight text-graphite sm:text-4xl font-heading">
                Built specifically for real store operations.
              </h2>
              <p className="mt-4 text-base text-graphite/70">
                Every screen and transaction was engineered for speed, accuracy, and clear accounting.
              </p>
            </div>

            <div className="mt-12 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
              {[
                {
                  icon: ShoppingCart,
                  title: 'High-Speed Cashier POS',
                  desc: 'Search or scan barcodes, choose customers, apply item or bill discounts, calculate 16% VAT, and print clean receipts.',
                },
                {
                  icon: Boxes,
                  title: 'Real-Time Inventory Ledger',
                  desc: 'Double-entry stock movement tracking for every sale, purchase, damaged item, expiry, or manual count adjustment.',
                },
                {
                  icon: CreditCard,
                  title: 'Multi-Payment Checkout',
                  desc: 'Process Cash with automatic change calculation, initiate M-Pesa STK push, accept debit/credit cards, or store credit.',
                },
                {
                  icon: UsersRound,
                  title: 'Customer & Supplier Accounts',
                  desc: 'Keep track of customer purchase history and outstanding balances. Manage supplier purchase orders and stock intake.',
                },
                {
                  icon: BarChart3,
                  title: 'Financial & Sales Analytics',
                  desc: 'Monitor real-time revenue, gross profit estimates, top products, payment breakdown, and low stock warnings.',
                },
                {
                  icon: ShieldCheck,
                  title: 'SellFlux Ready & Multi-Tenant',
                  desc: 'Tenant-isolated PostgreSQL architecture ready to sync catalog, sales trends, and inventory intelligence to SellFlux AI.',
                },
              ].map(({ icon: Icon, title, desc }) => (
                <Card key={title} className="p-6 transition hover:border-lime hover:shadow-lg">
                  <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-lime text-graphite font-bold">
                    <Icon size={22} />
                  </div>
                  <h3 className="mt-5 text-lg font-bold text-graphite font-heading">{title}</h3>
                  <p className="mt-2 text-sm text-graphite/65 leading-relaxed">{desc}</p>
                </Card>
              ))}
            </div>
          </div>
        </section>

        {/* Workflow Progression */}
        <section id="workflow" className="bg-graphite py-20 text-champagne">
          <div className="mx-auto max-w-7xl px-6 lg:px-12">
            <div className="max-w-2xl">
              <Badge variant="lime" className="px-3 py-1">Business Cycle</Badge>
              <h2 className="mt-3 text-3xl font-extrabold tracking-tight sm:text-4xl font-heading">
                From stock delivery to daily profits.
              </h2>
              <p className="mt-3 text-sm text-champagne/70">
                A seamless flow where backend transaction integrity protects every shilling.
              </p>
            </div>

            <div className="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
              {[
                { step: '01', title: 'Catalog & Purchase', desc: 'Add products, categories, SKU barcodes, and receive purchase orders from suppliers.' },
                { step: '02', title: 'Open Register & Sell', desc: 'Cashier opens cash float, scans items into cart, and selects customer payment.' },
                { step: '03', title: 'Atomic Transaction', desc: 'Stock is decremented, movement ledger logged, cash register updated, receipt generated.' },
                { step: '04', title: 'Audit & Insights', desc: 'Review daily revenue, profit margins, shift reconciliation, and inventory valuation.' },
              ].map(({ step, title, desc }) => (
                <div
                  key={step}
                  className="rounded-2xl border border-white/10 bg-white/5 p-6 relative overflow-hidden"
                >
                  <span className="text-2xl font-black text-lime font-heading">{step}</span>
                  <h3 className="mt-4 text-lg font-bold text-champagne font-heading">{title}</h3>
                  <p className="mt-2 text-xs text-champagne/70 leading-relaxed">{desc}</p>
                </div>
              ))}
            </div>
          </div>
        </section>

        <section className="border-y border-graphite/10 bg-white py-16">
          <div className="mx-auto flex max-w-7xl flex-col items-start justify-between gap-6 px-6 sm:flex-row sm:items-center lg:px-12">
            <div>
              <p className="text-xs font-bold uppercase tracking-wider text-graphite/50">Your business, in one place</p>
              <h2 className="mt-2 text-2xl font-extrabold text-graphite font-heading">Set up your workspace and get started.</h2>
            </div>
            <div className="flex flex-wrap gap-3">
              <Link to="/register" className="inline-flex h-11 items-center rounded-lg bg-lime px-5 text-sm font-bold text-graphite hover:bg-[#a8e83b] transition">Create an account</Link>
              <Link to="/login" className="inline-flex h-11 items-center rounded-lg border border-graphite/20 bg-white px-5 text-sm font-bold text-graphite hover:bg-champagne-light transition">Sign in</Link>
            </div>
          </div>
        </section>
      </main>

      {/* Footer */}
      <footer className="border-t border-graphite/10 bg-white py-12">
        <div className="mx-auto flex max-w-7xl flex-col items-center justify-between gap-6 px-6 sm:flex-row lg:px-12">
          <div className="flex items-center gap-3">
            <div className="flex h-8 w-8 items-center justify-center rounded-xl bg-graphite text-lime font-black text-sm font-heading">
              P
            </div>
            <span className="text-sm font-bold text-graphite font-heading">poss POS Platform</span>
            <span className="text-xs text-graphite/40">·</span>
            <span className="text-xs text-graphite/60">KES (Kenyan Shilling)</span>
          </div>

          <p className="text-xs text-graphite/50">
            © {currentYear} Poss Platform. Commercial Point of Sale Foundation.
          </p>

          <div className="flex items-center gap-5 text-xs font-semibold text-graphite/70">
            <Link to="/login" className="hover:text-graphite">Sign in</Link>
            <Link to="/register" className="hover:text-graphite">Create Account</Link>
            <a href="mailto:support@poss.local" className="hover:text-graphite">Support</a>
          </div>
        </div>
      </footer>
    </div>
  )
}

