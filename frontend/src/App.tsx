import { useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Activity,
  ArrowUpRight,
  Bell,
  BadgeDollarSign,
  Boxes,
  ChartNoAxesCombined,
  ChevronDown,
  CircleHelp,
  CreditCard,
  CalendarDays,
  LayoutDashboard,
  LogOut,
  Menu,
  PackageSearch,
  PanelLeftClose,
  Search,
  Settings2,
  ShoppingBag,
  ShoppingCart,
  Utensils,
  UsersRound,
  Wifi,
  WifiOff,
  X,
} from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link, NavLink, Navigate, Route, Routes, useLocation, useNavigate } from 'react-router-dom'
import { getBusinesses, getCurrentBusiness, logout } from './api/auth'
import { getApiHealth } from './api/client'
import { getInventory, getReportSummary, getSales } from './api/commerce'
import AuthLayout from './features/auth/AuthLayout'
import AcceptInvitationPage from './features/auth/AcceptInvitationPage'
import EmailVerificationPage from './features/auth/EmailVerificationPage'
import ForgotPasswordPage from './features/auth/ForgotPasswordPage'
import LoginPage from './features/auth/LoginPage'
import CustomersPage from './pages/CustomersPage'
import AppointmentsPage from './pages/AppointmentsPage'
import CommissionsPage from './pages/CommissionsPage'
import InventoryPage from './pages/InventoryPage'
import KitchenOrdersPage from './pages/KitchenOrdersPage'
import PosPage from './pages/PosPage'
import ProductsPage from './pages/ProductsPage'
import PurchasesPage from './pages/PurchasesPage'
import RegisterPage from './features/auth/RegisterPage'
import ResetPasswordPage from './features/auth/ResetPasswordPage'
import LandingPage from './pages/LandingPage'
import ReportsPage from './pages/ReportsPage'
import SalesPage from './pages/SalesPage'
import SettingsPage from './pages/SettingsPage'
import StaffPage from './pages/StaffPage'
import SalonStaffPage from './pages/SalonStaffPage'
import { formatCurrency, getInitials } from './lib/utils'

const navigation = [
  { label: 'Overview', to: '/dashboard', icon: LayoutDashboard, permission: 'reports.read' },
  { label: 'Point of sale', to: '/pos', icon: ShoppingCart, permission: 'sales.create' },
  { label: 'Kitchen orders', to: '/kitchen', icon: Utensils, permission: 'restaurant.manage', module: 'restaurant' },
  { label: 'Appointments', to: '/appointments', icon: CalendarDays, permission: 'appointments.manage', module: 'appointments' },
  { label: 'Commissions', to: '/commissions', icon: BadgeDollarSign, permission: 'commissions.manage', module: 'appointments' },
  { label: 'Products', to: '/products', icon: Boxes, permission: 'products.read' },
  { label: 'Inventory', to: '/inventory', icon: PackageSearch, permission: 'inventory.read' },
  { label: 'Sales', to: '/sales', icon: CreditCard, permission: 'sales.read' },
  { label: 'Customers', to: '/customers', icon: UsersRound, permission: 'customers.read' },
  { label: 'Purchases', to: '/purchases', icon: ShoppingBag, permission: 'purchases.read' },
  { label: 'Reports', to: '/reports', icon: ChartNoAxesCombined, permission: 'reports.read' },
]

const pages: Record<string, string> = {
  '/pos': 'Point of sale',
  '/kitchen': 'Kitchen orders',
  '/appointments': 'Appointments',
  '/commissions': 'Commissions',
  '/products': 'Products',
  '/inventory': 'Inventory',
  '/sales': 'Sales',
  '/customers': 'Customers',
  '/purchases': 'Purchases',
  '/reports': 'Reports',
  '/team': 'Team',
  '/salon-staff': 'Staff management',
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

function Dashboard({ userName, businessName, currency }: { userName: string; businessName: string; currency: string }) {
  const { data: health, isSuccess } = useQuery({ queryKey: ['api-health'], queryFn: getApiHealth, retry: 1 })
  const summaryQuery = useQuery({ queryKey: ['report-summary', '7days'], queryFn: () => getReportSummary('7days'), retry: 1 })
  const salesQuery = useQuery({ queryKey: ['sales'], queryFn: getSales, retry: 1 })
  const inventoryQuery = useQuery({ queryKey: ['inventory'], queryFn: getInventory, retry: 1 })
  const summary = summaryQuery.data
  const recentSales = salesQuery.data?.slice(0, 3) ?? []
  const lowStockItems = inventoryQuery.data?.filter((item) => item.is_low_stock).slice(0, 3) ?? []
  const firstName = userName.trim().split(/\s+/)[0] || 'there'
  const [todayLabel] = useState(() => new Intl.DateTimeFormat('en-KE', { weekday: 'long', month: 'long', day: 'numeric' }).format(new Date()))
  const metrics = [
    { label: "Today's sales", icon: CreditCard, value: summary ? formatCurrency(summary.today.revenue, currency) : '—', note: 'Confirmed revenue' },
    { label: 'Transactions', icon: Activity, value: summary ? String(summary.today.transactions) : '—', note: 'Completed today' },
    { label: "Today's profit", icon: ChartNoAxesCombined, value: summary ? formatCurrency(summary.today.profit_estimate, currency) : '—', note: 'Estimated margin' },
    { label: 'Low stock items', icon: Boxes, value: summary ? String(summary.inventory.low_stock_count) : '—', note: 'At or below reorder level' },
  ]

  return (
    <div className="dashboard-page">
      <section className="welcome-row">
        <div>
          <p className="eyebrow">{todayLabel.toUpperCase()}</p>
          <h1>Welcome back, {firstName}.</h1>
          <p className="welcome-copy">Here is what is happening at {businessName}.</p>
        </div>
        <Link className="primary-button" to="/pos">
          <ShoppingCart size={17} />
          Open point of sale
        </Link>
      </section>

      <section aria-label="Business metrics" className="metric-grid">
        {metrics.map(({ label, icon: Icon, value, note }, index) => (
          <article className={`metric-card metric-${index}`} key={label}>
            <div className="metric-top">
              <span>{label}</span>
              <Icon size={17} />
            </div>
            <p className="metric-value">{summaryQuery.isError ? 'Unavailable' : value}</p>
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
          <div className="sales-trend" aria-label="Sales totals by day for the last seven days">
            {summary?.sales_trend.length ? summary.sales_trend.slice(-7).map((day) => {
              const maxRevenue = Math.max(...summary.sales_trend.map((entry) => entry.total), 1)
              return (
                <div className="trend-day" key={day.date} title={`${day.date}: ${formatCurrency(day.total, currency)}`}>
                  <span className="trend-bar" style={{ height: `${Math.max(8, (day.total / maxRevenue) * 100)}%` }} />
                  <small>{new Date(`${day.date}T00:00:00`).toLocaleDateString('en-KE', { weekday: 'short' })}</small>
                </div>
              )
            }) : (
              <div className="chart-empty">
                <div className="chart-mark"><ChartNoAxesCombined size={23} /></div>
                <strong>{summaryQuery.isPending ? 'Loading sales activity' : 'No sales in this period'}</strong>
                <span>{summaryQuery.isError ? 'Sales performance could not be loaded.' : 'Complete a sale to start your trend.'}</span>
              </div>
            )}
            {summary?.sales_trend.length ? (
              <div className="trend-caption"><strong>{formatCurrency(summary.period.revenue, currency)}</strong><span>Last 7 days · {summary.period.transactions} transactions</span></div>
            ) : null}
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
            <div><span>Business data</span><strong>{summaryQuery.isSuccess ? 'Connected' : summaryQuery.isPending ? 'Checking' : 'Unavailable'}</strong></div>
          </div>
          <div className="setup-note">
            <Activity size={16} />
            <p>{summaryQuery.isError ? 'The business data service could not be reached.' : 'Sales and inventory are synced from your business workspace.'}</p>
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
          {salesQuery.isError ? <div className="empty-state"><strong>Sales could not be loaded</strong><span>Check the API connection and business context.</span></div> : recentSales.length ? (
            <div className="dashboard-record-list">{recentSales.map((sale) => <div key={sale.id}><span><strong>{sale.receipt_number}</strong><small>{new Date(sale.created_at).toLocaleString()}</small></span><b>{formatCurrency(Number(sale.total), currency)}</b></div>)}</div>
          ) : <div className="empty-state"><div className="empty-icon"><CreditCard size={19} /></div><strong>{salesQuery.isPending ? 'Loading sales' : 'No sales recorded yet'}</strong><span>Completed transactions will appear here.</span></div>}
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
          {inventoryQuery.isError ? <div className="empty-state"><strong>Inventory could not be loaded</strong><span>Check the API connection and business context.</span></div> : lowStockItems.length ? (
            <div className="dashboard-record-list">{lowStockItems.map((item) => <div key={item.product_id}><span><strong>{item.name}</strong><small>{item.sku}</small></span><b className="stock-warning">{item.quantity_on_hand} left</b></div>)}</div>
          ) : <div className="empty-state"><div className="empty-icon"><Boxes size={20} /></div><strong>{inventoryQuery.isPending ? 'Checking stock' : 'Nothing to flag'}</strong><span>Products at their reorder level appear here.</span></div>}
        </article>
      </section>

      <p className="dashboard-footnote"><i /> Figures update from confirmed transactions only</p>
    </div>
  )
}

function POSPage({ canManageRegisters }: { canManageRegisters: boolean }) {
  return <PosPage canManageRegisters={canManageRegisters} />
}

function clearAuthSession() {
  localStorage.removeItem('pos_token')
  localStorage.removeItem('pos_user')
  localStorage.removeItem('pos_business_id')
  localStorage.removeItem('pos_business_name')
  localStorage.removeItem('pos_user_role')
  window.dispatchEvent(new Event('pos-auth-changed'))
}

function getStoredProfile(): { id: number | null; name: string } {
  try {
    const storedUser = localStorage.getItem('pos_user')
    const user = storedUser ? JSON.parse(storedUser) as { id?: unknown; name?: unknown } : null

    return {
      id: typeof user?.id === 'number' ? user.id : null,
      name: typeof user?.name === 'string' && user.name.trim() ? user.name : 'Account',
    }
  } catch {
    return { id: null, name: 'Account' }
  }
}

function ProtectedAppShell() {
  const [collapsed, setCollapsed] = useState(false)
  const [mobileOpen, setMobileOpen] = useState(false)
  const location = useLocation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [isLoggingOut, setIsLoggingOut] = useState(false)
  const title = location.pathname === '/dashboard' ? 'Overview' : pages[location.pathname] ?? 'Overview'
  const profile = getStoredProfile()
  const [businessId, setBusinessId] = useState(() => localStorage.getItem('pos_business_id'))
  const businessesQuery = useQuery({ queryKey: ['businesses'], queryFn: getBusinesses, retry: 1 })
  const businessQuery = useQuery({
    queryKey: ['current-business', businessId, profile.id],
    queryFn: getCurrentBusiness,
    enabled: Boolean(businessId),
    retry: 1,
  })
  const businessName = businessQuery.data?.name ?? localStorage.getItem('pos_business_name') ?? 'Business workspace'
  const businessCurrency = businessQuery.data?.currency ?? 'KES'
  const userRole = businessQuery.data?.role ?? localStorage.getItem('pos_user_role') ?? 'Member'
  const enabledModules = businessQuery.data?.modules ?? []
  const userPermissions = businessQuery.data?.permissions ?? []
  const canAccess = (permission: string, module?: string) =>
    (userPermissions.includes('*') || userPermissions.includes(permission))
    && (!module || enabledModules.includes(module))
  const canManageStaff = canAccess('staff.manage')
  const accessDenied = <div role="status" className="rounded-2xl border border-graphite/10 bg-white p-6 text-sm text-graphite/65">You do not have permission to access this area.</div>
  const visibleNavigation = navigation.filter(({ permission, module }) =>
    canAccess(permission, module),
  )

  const switchBusiness = (nextBusinessId: string) => {
    const business = businessesQuery.data?.find((item) => item.id === nextBusinessId)
    if (!business || nextBusinessId === businessId) return
    localStorage.setItem('pos_business_id', business.id)
    localStorage.setItem('pos_business_name', business.name)
    localStorage.setItem('pos_user_role', business.role)
    queryClient.clear()
    setBusinessId(business.id)
    navigate('/dashboard', { replace: true })
  }

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
          <div className="business-monogram">{getInitials(businessName).slice(0, 1)}</div>
          <div className="business-name">
            <strong>{businessName}</strong>
            {businessesQuery.data && businessesQuery.data.length > 1
              ? <select aria-label="Switch business" value={businessId ?? ''} onChange={(event) => switchBusiness(event.target.value)} className="mt-1 w-full bg-transparent text-[11px] text-white/65 outline-none">
                  {businessesQuery.data.map((business) => <option className="text-graphite" key={business.id} value={business.id}>{business.name}</option>)}
                </select>
              : <span>Current business</span>}
          </div>
          {businessesQuery.data && businessesQuery.data.length > 1 && <ChevronDown size={15} />}
        </div>

        <p className="nav-caption">WORKSPACE</p>
        <nav className="main-nav" aria-label="Main navigation">
          {visibleNavigation.map(({ label, to, icon: Icon }) => (
            <NavLink onClick={() => setMobileOpen(false)} key={to} to={to} className={({ isActive }) => `nav-item ${isActive ? 'nav-active' : ''}`}>
              <Icon size={18} />
              <span>{label}</span>
              {to === '/pos' && <kbd>F8</kbd>}
            </NavLink>
          ))}
          {canManageStaff && <NavLink onClick={() => setMobileOpen(false)} to="/team" className={({ isActive }) => `nav-item ${isActive ? 'nav-active' : ''}`}>
            <UsersRound size={18} />
            <span>Team</span>
          </NavLink>}
          {canManageStaff && enabledModules.includes('appointments') && <NavLink onClick={() => setMobileOpen(false)} to="/salon-staff" className={({ isActive }) => `nav-item ${isActive ? 'nav-active' : ''}`}>
            <UsersRound size={18} />
            <span>Staff management</span>
          </NavLink>}
        </nav>

        <div className="sidebar-bottom">
          <div className="help-card">
            <div className="help-mark"><CircleHelp size={16} /></div>
            <strong>Need a hand?</strong>
            <span>Visit the help centre</span>
          </div>
          {canAccess('settings.manage') && <NavLink onClick={() => setMobileOpen(false)} to="/settings" className="nav-item settings-link">
            <Settings2 size={18} />
            <span>Settings</span>
          </NavLink>}
          <div className="user-profile">
            <div className="avatar">{getInitials(profile.name)}</div>
            <div className="user-label">
              <strong>{profile.name}</strong>
              <span>{userRole.charAt(0).toUpperCase() + userRole.slice(1)}</span>
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
              <div className="avatar avatar-small">{getInitials(profile.name)}</div>
              <ChevronDown size={14} />
            </div>
          </div>
        </header>

        <div className="page-content">
          <Routes>
            <Route path="/" element={<Navigate to="/dashboard" replace />} />
            <Route path="/dashboard" element={canAccess('reports.read') ? <Dashboard userName={profile.name} businessName={businessName} currency={businessCurrency} /> : accessDenied} />
            <Route path="/pos" element={canAccess('sales.create') ? <POSPage canManageRegisters={canAccess('registers.manage')} /> : accessDenied} />
            <Route path="/kitchen" element={canAccess('restaurant.manage', 'restaurant') ? <KitchenOrdersPage /> : accessDenied} />
            <Route path="/appointments" element={canAccess('appointments.manage', 'appointments') ? <AppointmentsPage /> : accessDenied} />
            <Route path="/commissions" element={canAccess('commissions.manage', 'appointments') ? <CommissionsPage /> : accessDenied} />
            <Route path="/products" element={canAccess('products.read') ? <ProductsPage /> : accessDenied} />
            <Route path="/inventory" element={canAccess('inventory.read') ? <InventoryPage /> : accessDenied} />
            <Route path="/sales" element={canAccess('sales.read') ? <SalesPage canRefund={canAccess('sales.refund')} /> : accessDenied} />
            <Route path="/customers" element={canAccess('customers.read') ? <CustomersPage /> : accessDenied} />
            <Route path="/purchases" element={canAccess('purchases.read') ? <PurchasesPage /> : accessDenied} />
            <Route path="/reports" element={canAccess('reports.read') ? <ReportsPage /> : accessDenied} />
            <Route path="/team" element={canManageStaff ? <StaffPage /> : accessDenied} />
            <Route path="/salon-staff" element={canManageStaff && enabledModules.includes('appointments') ? <SalonStaffPage /> : accessDenied} />
            <Route path="/settings" element={canAccess('settings.manage') ? <SettingsPage /> : accessDenied} />
            <Route path="*" element={<Navigate to="/dashboard" replace />} />
          </Routes>

          <footer className="app-footer">
            <span>Poss workspace</span>
            <span>{businessQuery.data?.timezone ?? 'Africa/Nairobi'} <i>·</i> {businessCurrency}</span>
            <a href="mailto:support@poss.local">Support</a>
          </footer>
        </div>
      </main>

      <nav className="mobile-nav" aria-label="Quick navigation">
        {canAccess('reports.read') && <NavLink to="/dashboard" aria-label="Overview" className={({ isActive }) => `mobile-nav-link ${isActive ? 'mobile-nav-active' : ''}`}>
          <LayoutDashboard size={19} /><span>Home</span>
        </NavLink>}
        {canAccess('sales.create') && <NavLink to="/pos" aria-label="Point of sale" className={({ isActive }) => `mobile-nav-link mobile-nav-pos ${isActive ? 'mobile-nav-active' : ''}`}>
          <ShoppingCart size={20} /><span>Sell</span>
        </NavLink>}
        {canAccess('sales.read') && <NavLink to="/sales" aria-label="Sales" className={({ isActive }) => `mobile-nav-link ${isActive ? 'mobile-nav-active' : ''}`}>
          <CreditCard size={19} /><span>Sales</span>
        </NavLink>}
        <button type="button" className={`mobile-nav-link ${mobileOpen ? 'mobile-nav-active' : ''}`} onClick={() => setMobileOpen(true)} aria-label="Open all sections">
          <Menu size={20} /><span>More</span>
        </button>
      </nav>

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
  const location = useLocation()

  useEffect(() => {
    const syncToken = () => setToken(localStorage.getItem('pos_token'))
    window.addEventListener('pos-auth-changed', syncToken)
    return () => window.removeEventListener('pos-auth-changed', syncToken)
  }, [])

  if (location.pathname === '/accept-invite') {
    return <AuthLayout><AcceptInvitationPage /></AuthLayout>
  }

  if (!token) {
    return <PublicApp />
  }

  return <ProtectedAppShell />
}
