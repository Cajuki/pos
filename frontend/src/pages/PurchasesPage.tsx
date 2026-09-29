import { ClipboardList } from 'lucide-react'
import { Card } from '../components/ui/Card'

export default function PurchasesPage() {
  return (
    <section className="module-page">
      <div className="mb-6">
        <p className="eyebrow">Procurement</p>
        <h1>Purchases</h1>
      </div>

      <Card className="flex min-h-64 flex-col items-center justify-center gap-3 p-8 text-center">
        <div className="grid h-12 w-12 place-items-center rounded-full bg-lime/20 text-graphite"><ClipboardList className="h-5 w-5" /></div>
        <h2 className="text-lg">Purchase orders are not connected yet</h2>
        <p className="max-w-md text-sm leading-6 text-graphite/60">Supplier and goods-receiving workflows will appear here when their business APIs are implemented.</p>
      </Card>
    </section>
  )
}
