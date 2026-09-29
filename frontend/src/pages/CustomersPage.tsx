import { UsersRound } from 'lucide-react'
import { Card } from '../components/ui/Card'

export default function CustomersPage() {
  return (
    <section className="module-page">
      <div className="mb-6">
        <p className="eyebrow">Relationships</p>
        <h1>Customers</h1>
      </div>

      <Card className="flex min-h-64 flex-col items-center justify-center gap-3 p-8 text-center">
        <div className="grid h-12 w-12 place-items-center rounded-full bg-lime/20 text-graphite"><UsersRound className="h-5 w-5" /></div>
        <h2 className="text-lg">Customer records are not connected yet</h2>
        <p className="max-w-md text-sm leading-6 text-graphite/60">Customer management will appear here when its business API is implemented.</p>
      </Card>
    </section>
  )
}
