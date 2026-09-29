import { useQuery } from '@tanstack/react-query'
import {
  Activity,
  ArrowUpRight,
  Bell,
  Boxes,
  ChartNoAxesCombined,
  ChevronDown,
  CircleHelp,
  CreditCard,
  LayoutDashboard,
  LogOut,
  Menu,
  PackageSearch,
  PanelLeftClose,
  Search,
  Settings2,
  ShoppingBag,
  ShoppingCart,
  UsersRound,
  Wifi,
  WifiOff,
  X,
} from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link, NavLink, Navigate, Route, Routes, useLocation, useNavigate } from 'react-router-dom'
import { logout } from './api/auth'
import { getApiHealth } from './api/client'
import AuthLayout from './features/auth/AuthLayout'
import EmailVerificationPage from './features/auth/EmailVerificationPage'
import ForgotPasswordPage from './features/auth/ForgotPasswordPage'
import LoginPage from './features/auth/LoginPage'
import CustomersPage from './pages/CustomersPage'
import InventoryPage from './pages/InventoryPage'
import PosPage from './pages/PosPage'
import ProductsPage from './pages/ProductsPage'
import PurchasesPage from './pages/PurchasesPage'
import RegisterPage from './features/auth/RegisterPage'
import ResetPasswordPage from './features/auth/ResetPasswordPage'
import LandingPage from './pages/LandingPage'
import ReportsPage from './pages/ReportsPage'
import SalesPage from './pages/SalesPage'

const navigation = [
  { label: 'Overview', to: '/dashboard', icon: LayoutDashboard },
  { label: 'Point of sale', to: '/pos', icon: ShoppingCart },
  { label: 'Products', to: '/products', icon: Boxes },
  { label: 'Inventory', to: '/inventory', icon: PackageSearch },
  { label: 'Sales', to: '/sales', icon: CreditCard },
  { label: 'Customers', to: '/customers', icon: UsersRound },
  { label: 'Purchases', to: '/purchases', icon: ShoppingBag },
  { label: 'Reports', to: '/reports', icon: ChartNoAxesCombined },
]

const pages: Record<string, string> = {
  '/pos': 'Point of sale',
  '/products': 'Products',
  '/inventory': 'Inventory',
  '/sales': 'Sales',
  '/customers': 'Customers',
  '/purchases': 'Purchases',
  '/reports': 'Reports',
  '/settings': 'Settings',
}

function ApiStatus() {
  const { isPending, isSuccess } = useQuery({ queryKey: ['api-health'], queryFn: getApiHealth, retry: 1 })
  return (
    <div className={`connection ${isSuccess ? 'online' : ''}`} title={isSuccess ? 'API connected' : isPending ? 'Connecting to API' : 'API unavailable'}>
      {isSuccess ? <Wifi size={15} /> : <WifiOff size={15} />}
      <span>{isSuccess ? 'API connected' : isPending ? 'Connecting' : 'API unavailable'}</span>
      <i />
    </div>
  )
}

function Dashboard() {
  const { data: health, isSuccess } = useQuery({ queryKey: ['api-health'], queryFn: getApiHealth, retry: 1 })
  const metrics = [
    { label: "Today's sales", icon: CreditCard, note: 'Sales data will appear here' },
    { label: 'Transactions', icon: Activity, note: 'Transaction count will appear here' },
    { label: "Today's profit", icon: ChartNoAxesCombined, note: 'Profit data will appear here' },
    { label: 'Low stock items', icon: Boxes, note: 'Inventory data will appear here' },
  ]

  return (
    <div className="dashboard-page">
      <section className="welcome-row">
        <div>
          <p className="eyebrow">MONDAY, SEPTEMBER 28</p>
          <h1>Your business, at a glance.</h1>
          <p className="welcome-copy">A clear view of the day starts here.</p>
        </div>
        <Link className="primary-button" to="/pos">
          <ShoppingCart size={17} />
          Open point of sale
        </Link>
      </section>

      <section aria-label="Business metrics" className="metric-grid">
        {metrics.map(({ label, icon: Icon, note }, index) => (
          <article className={`metric-card metric-${index}`} key={label}>
            <div className="metric-top">
              <span>{label}</span>
              <Icon size={17} />
            </div>
            <p className="metric-value">—</p>
            <p className="metric-note">{note}</p>
          </article>
        ))}
      </section>

      <section className="overview-grid">
        <article className="panel sales-panel">
          <div className="panel-heading">
            <div>
              <p className="eyebrow">PERFORMANCE</p>
              <h2>Sales overview</h2>
            </div>
            <button className="select-button" type="button">
              Last 7 days <ChevronDown size={14} />
            </button>
          </div>
          <div className="chart-empty">
            <div className="chart-mark">
              <ChartNoAxesCombined size={23} />
            </div>
            <strong>Your sales trend will take shape here</strong>
            <span>Complete sales to see performance over time.</span>
            <div className="chart-baseline" aria-hidden="true">
              {[1, 2, 3, 4, 5, 6, 7].map((n) => <i key={n} />)}
            </div>
          </div>
        </article>

        <article className="panel setup-panel">
          <div className="panel-heading">
            <div>
              <p className="eyebrow">WORKSPACE</p>
              <h2>System status</h2>
            </div>
            <span className={`status-orb ${isSuccess ? 'status-good' : ''}`} />
          </div>
          <div className="status-list">
            <div><span>REST API</span><strong>{isSuccess ? 'Online' : 'Checking'}</strong></div>
            <div><span>API version</span><strong>{health?.version ?? 'v1'}</strong></div>
            <div><span>Business data</span><strong>Not connected</strong></div>
          </div>
          <div className="setup-note">
            <Activity size={16} />
            <p>Core services are ready. Business modules will connect here as they are enabled.</p>
          </div>
        </article>
      </section>

      <section className="bottom-grid">
        <article className="panel table-panel">
          <div className="panel-heading">
            <div>
              <p className="eyebrow">ACTIVITY</p>
              <h2>Recent sales</h2>
            </div>
            <Link className="text-link" to="/sales">
              View sales <ArrowUpRight size={14} />
            </Link>
          </div>
          <div className="empty-state">
            <div className="empty-icon"><CreditCard size={19} /></div>
            <strong>No sales recorded yet</strong>
            <span>Completed transactions will appear here.</span>
          </div>
        </article>

        <article className="panel stock-panel">
          <div className="panel-heading">
            <div>
              <p className="eyebrow">INVENTORY</p>
              <h2>Stock watch</h2>
            </div>
            <Link className="icon-link" to="/inventory" aria-label="Open inventory">
              <ArrowUpRight size={16} />
            </Link>
          </div>
          <div className="empty-state">
            <div className="empty-icon"><Boxes size={20} /></div>
            <strong>Nothing to flag</strong>
            <span>Stock alerts appear once inventory is connected.</span>
          </div>
        </article>
      </section>

      <p className="dashboard-footnote"><i /> Figures update from confirmed transactions only</p>
    </div>
  )
}

function ModulePage({ title }: { title: string }) {
  return (
    <section className="module-page">
      <p className="eyebrow">BUSINESS OPERATIONS</p>
      <h1>{title}</h1>
      <div className="module-empty panel">
        <div className="empty-icon"><Settings2 size={20} /></div>
        <strong>{title} data is not connected yet</strong>
        <span>This workspace shows live information only. Connect the module API to manage {title.toLowerCase()} here.</span>
      </div>
    </section>
  )
}

function POSPage() {
  return <PosPage />
}

function clearAuthSession() {
  localStorage.removeItem('pos_token')
  localStorage.removeItem('pos_user')
  localStorage.removeItem('pos_business_id')
  window.dispatchEvent(new Event('pos-auth-changed'))
}

function ProtectedAppShell() {
  const [collapsed, setCollapsed] = useState(false)
  const [mobileOpen, setMobileOpen] = useState(false)
  const location = useLocation()
  const navigate = useNavigate()
  const [isLoggingOut, setIsLoggingOut] = useState(false)
  const title = location.pathname === '/dashboard' ? 'Overview' : pages[location.pathname] ?? 'Overview'

  const handleLogout = () => {
    if (isLoggingOut) return

    const token = localStorage.getItem('pos_token')
    setIsLoggingOut(true)
    clearAuthSession()
    navigate('/', { replace: true })

    if (token) {
      void logout(token).catch(() => undefined)
    }
  }

  return (
    <div className={`app-shell ${collapsed ? 'sidebar-collapsed' : ''}`}>
      {mobileOpen && <button className="mobile-scrim" aria-label="Close navigation" onClick={() => setMobileOpen(false)} />}
      <aside className={`sidebar ${mobileOpen ? 'mobile-open' : ''}`}>
        <Link to="/dashboard" className="brand-lockup" aria-label="Poss home">
          <span className="brand-symbol"><i /><i /><i /></span>
          <span className="brand-name">poss<span>.</span></span>
        </Link>

        <div className="business-switcher">
          <div className="business-monogram">D</div>
          <div className="business-name">
            <strong>Demo Retail Ltd</strong>
            <span>Retail workspace</span>
          </div>
          <ChevronDown size={15} />
        </div>

        <p className="nav-caption">WORKSPACE</p>
        <nav className="main-nav" aria-label="Main navigation">
          {navigation.map(({ label, to, icon: Icon }) => (
            <NavLink onClick={() => setMobileOpen(false)} key={to} to={to} className={({ isActive }) => `nav-item ${isActive ? 'nav-active' : ''}`}>
              <Icon size={18} />
              <span>{label}</span>
              {to === '/pos' && <kbd>F8</kbd>}
            </NavLink>
          ))}
        </nav>

        <div className="sidebar-bottom">
          <div className="help-card">
            <div className="help-mark"><CircleHelp size={16} /></div>
            <strong>Need a hand?</strong>
            <span>Visit the help centre</span>
          </div>
          <NavLink to="/settings" className="nav-item settings-link">
            <Settings2 size={18} />
            <span>Settings</span>
          </NavLink>
          <div className="user-profile">
            <div className="avatar">AM</div>
            <div className="user-label">
              <strong>Alex Morgan</strong>
              <span>Administrator</span>
            </div>
            <button className="more-button" aria-label="User menu"><Menu size={17} /></button>
          </div>
        </div>
      </aside>

      <main className="main-area">
        <header className="topbar">
          <div className="topbar-left">
            <button className="collapse-button desktop-control" onClick={() => setCollapsed(!collapsed)} aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}>
              <PanelLeftClose size={18} />
            </button>
            <button className="collapse-button mobile-control" onClick={() => setMobileOpen(true)} aria-label="Open navigation">
              <Menu size={19} />
            </button>
            <div className="breadcrumb">
              <span>Workspace</span>
              <b>/</b>
              <strong>{title}</strong>
            </div>
          </div>

          <div className="topbar-actions">
            <ApiStatus />
            <button className="top-icon search-action" aria-label="Search"><Search size={18} /></button>
            <button className="top-icon" aria-label="Notifications"><Bell size={18} /></button>
            <button
              className="top-icon"
              type="button"
              title="Log out"
              aria-label="Log out"
              disabled={isLoggingOut}
              onClick={handleLogout}
            >
              <LogOut size={18} />
            </button>
            <div className="top-user">
              <div className="avatar avatar-small">AM</div>
              <ChevronDown size={14} />
            </div>
          </div>
        </header>

        <div className="page-content">
          <Routes>
            <Route path="/" element={<Navigate to="/dashboard" replace />} />
            <Route path="/dashboard" element={<Dashboard />} />
            <Route path="/pos" element={<POSPage />} />
            <Route path="/products" element={<ProductsPage />} />
            <Route path="/inventory" element={<InventoryPage />} />
            <Route path="/sales" element={<SalesPage />} />
            <Route path="/customers" element={<CustomersPage />} />
            <Route path="/purchases" element={<PurchasesPage />} />
            <Route path="/reports" element={<ReportsPage />} />
            <Route path="/settings" element={<ModulePage title="Settings" />} />
            <Route path="*" element={<Navigate to="/dashboard" replace />} />
          </Routes>

          <footer className="app-footer">
            <span>Poss workspace</span>
            <span>Kenya <i>·</i> KES</span>
            <a href="mailto:support@poss.local">Support</a>
          </footer>
        </div>
      </main>

      {mobileOpen && <button className="mobile-close" onClick={() => setMobileOpen(false)} aria-label="Close menu"><X size={19} /></button>}
    </div>
  )
}

function PublicApp() {
  return (
    <Routes>
      <Route path="/" element={<LandingPage />} />
      <Route path="/login" element={<AuthLayout><LoginPage /></AuthLayout>} />
      <Route path="/register" element={<AuthLayout><RegisterPage /></AuthLayout>} />
      <Route path="/forgot-password" element={<AuthLayout><ForgotPasswordPage /></AuthLayout>} />
      <Route path="/reset-password" element={<AuthLayout><ResetPasswordPage /></AuthLayout>} />
      <Route path="/verify-email" element={<AuthLayout><EmailVerificationPage /></AuthLayout>} />
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  )
}

export default function App() {
  const [token, setToken] = useState(() => localStorage.getItem('pos_token'))

  useEffect(() => {
    const syncToken = () => setToken(localStorage.getItem('pos_token'))
    window.addEventListener('pos-auth-changed', syncToken)
    return () => window.removeEventListener('pos-auth-changed', syncToken)
  }, [])

  if (!token) {
    return <PublicApp />
  }

  return <ProtectedAppShell />
}
