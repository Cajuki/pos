import { useQuery } from '@tanstack/react-query'
import { CreditCard, DollarSign, PackageCheck, ShoppingBag } from 'lucide-react'
import { getBusinessSettings, getSales } from '../api/commerce'
import { DataTable } from '../components/ui/DataTable'
import { StatCard } from '../components/ui/StatCard'
import { formatCurrency } from '../lib/utils'

export default function ReportsPage() {
  const salesQuery = useQuery({ queryKey: ['sales'], queryFn: getSales })
  const settingsQuery = useQuery({ queryKey: ['business-settings'], queryFn: getBusinessSettings })
  const sales = salesQuery.data ?? []
  const currency = settingsQuery.data?.currency ?? 'KES'
  const revenueCents = sales.reduce((total, sale) => total + toCents(sale.total), 0)
  const unitsSold = sales.reduce((total, sale) => total + sale.items.reduce((itemTotal, item) => itemTotal + item.quantity, 0), 0)
  const cashSales = sales.filter((sale) => sale.payment_method === 'cash').length
  const rows = sales.map((sale) => ({
    ...sale,
    date: new Date(sale.created_at).toLocaleDateString(),
    units: sale.items.reduce((total, item) => total + item.quantity, 0),
    revenue: formatCurrency(Number(sale.total), currency),
  }))

  return (
    <section className="module-page">
      <div className="mb-6">
        <p className="eyebrow">Analytics</p>
        <h1>Reports</h1>
      </div>

      {salesQuery.isError && <p role="alert" className="mb-4 text-sm text-danger">Report data could not be loaded. Check the API connection and business context.</p>}

      <div className="mb-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <StatCard title="Sales loaded" value={salesQuery.isPending ? '—' : String(sales.length)} hint="Recent API results" icon={<ShoppingBag className="h-5 w-5" />} />
        <StatCard title="Revenue shown" value={salesQuery.isPending ? '—' : formatCurrency(revenueCents / 100, currency)} hint="From loaded sales" icon={<DollarSign className="h-5 w-5" />} />
        <StatCard title="Units shown" value={salesQuery.isPending ? '—' : String(unitsSold)} hint="From loaded sales" icon={<PackageCheck className="h-5 w-5" />} />
        <StatCard title="Cash transactions" value={salesQuery.isPending ? '—' : String(cashSales)} hint="Among loaded sales" icon={<CreditCard className="h-5 w-5" />} />
      </div>

      <DataTable
        loading={salesQuery.isPending}
        rows={rows}
        emptyMessage="No completed sales to report yet."
        columns={[
          { header: 'Receipt', accessor: 'receipt_number' },
          { header: 'Date', accessor: 'date' },
          { header: 'Payment', accessor: 'payment_method' },
          { header: 'Units', accessor: 'units' },
          { header: 'Revenue', accessor: 'revenue' },
          { header: 'Status', accessor: 'status' },
        ]}
      />
    </section>
  )
}

function toCents(amount: string): number {
  const [units, fraction = ''] = amount.split('.')
  return Number(units) * 100 + Number(fraction.padEnd(2, '0').slice(0, 2))
}
