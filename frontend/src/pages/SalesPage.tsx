import { useQuery } from '@tanstack/react-query'
import { getSales } from '../api/commerce'
import { Card } from '../components/ui/Card'
import { DataTable } from '../components/ui/DataTable'

export default function SalesPage() {
  const salesQuery = useQuery({ queryKey: ['sales'], queryFn: getSales })
  const sales = (salesQuery.data ?? []).map((sale) => ({
    ...sale,
    invoice: sale.receipt_number,
    channel: sale.payment_method === 'credit' ? 'Store credit' : 'Cash',
    total_label: `KES ${sale.total}`,
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
            { header: 'Channel', accessor: 'channel' },
            { header: 'Total', accessor: 'total_label' },
            { header: 'Status', accessor: 'status' },
          ]}
        />
      </Card>
    </section>
  )
}
