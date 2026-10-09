import { useQuery } from '@tanstack/react-query'
import { getBusinessSettings, getSales } from '../api/commerce'
import { Card } from '../components/ui/Card'
import { DataTable } from '../components/ui/DataTable'
import { formatCurrency } from '../lib/utils'

export default function SalesPage() {
  const salesQuery = useQuery({ queryKey: ['sales'], queryFn: getSales })
  const settingsQuery = useQuery({ queryKey: ['business-settings'], queryFn: getBusinessSettings })
  const currency = settingsQuery.data?.currency ?? 'KES'
  const sales = (salesQuery.data ?? []).map((sale) => ({
    ...sale,
    invoice: sale.receipt_number,
    customer_name: sale.customer?.name ?? 'Walk-in',
    channel: sale.payment_method === 'credit' ? 'Store credit' : sale.payment_method.toUpperCase(),
    discount_label: Number(sale.discount_amount) > 0 ? formatCurrency(Number(sale.discount_amount), currency) : '—',
    total_label: formatCurrency(Number(sale.total), currency),
    date: new Date(sale.created_at).toLocaleString(),
  }))

  return (
    <section className="module-page">
      <div className="mb-6">
        <p className="eyebrow">Revenue</p>
        <h1>Sales</h1>
      </div>

      <Card className="p-5">
        <div className="mb-4 flex items-center justify-between">
          <h2 className="text-xl font-bold text-graphite">Recorded sales</h2>
          <span className="text-sm text-graphite/60">{salesQuery.data?.length ?? 0} loaded</span>
        </div>

        {salesQuery.isError && <p role="alert" className="mb-4 text-sm text-danger">Sales could not be loaded. Check the API connection and business context.</p>}
        <DataTable
          loading={salesQuery.isPending}
          rows={sales}
          emptyMessage="No sales recorded yet."
          columns={[
            { header: 'Invoice', accessor: 'invoice' },
            { header: 'Date', accessor: 'date' },
            { header: 'Customer', accessor: 'customer_name' },
            { header: 'Channel', accessor: 'channel' },
            { header: 'Discount', accessor: 'discount_label' },
            { header: 'Total', accessor: 'total_label' },
            { header: 'Status', accessor: 'status' },
          ]}
        />
      </Card>
    </section>
  )
}
