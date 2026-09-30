import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ClipboardList, Plus, Trash2 } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { createPurchase, getProducts, getPurchases, getSuppliers, type Product } from '../api/commerce'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'
import { DataTable } from '../components/ui/DataTable'

interface DraftLine {
  product: Product
  quantity: number
  unit_cost: number
}

export default function PurchasesPage() {
  const queryClient = useQueryClient()
  const [isCreateOpen, setIsCreateOpen] = useState(false)
  const [supplierId, setSupplierId] = useState('')
  const [productId, setProductId] = useState('')
  const [quantity, setQuantity] = useState('1')
  const [unitCost, setUnitCost] = useState('')
  const [lines, setLines] = useState<DraftLine[]>([])
  const purchasesQuery = useQuery({ queryKey: ['purchases'], queryFn: getPurchases })
  const suppliersQuery = useQuery({ queryKey: ['suppliers'], queryFn: getSuppliers })
  const productsQuery = useQuery({ queryKey: ['products', 'all'], queryFn: () => getProducts() })
  const createMutation = useMutation({
    mutationFn: createPurchase,
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['purchases'] }),
        queryClient.invalidateQueries({ queryKey: ['inventory'] }),
        queryClient.invalidateQueries({ queryKey: ['products'] }),
        queryClient.invalidateQueries({ queryKey: ['report-summary'] }),
      ])
      setLines([])
      setSupplierId('')
      setIsCreateOpen(false)
    },
  })

  const selectedProduct = productsQuery.data?.find((product) => String(product.id) === productId)
  const resolvedUnitCost = unitCost || selectedProduct?.cost_price || selectedProduct?.unit_price || ''
  const orderTotal = lines.reduce((sum, line) => sum + line.quantity * line.unit_cost, 0)

  function addLine() {
    if (!selectedProduct) return
    const parsedQuantity = Number(quantity)
    const parsedCost = Number(resolvedUnitCost)
    if (!Number.isInteger(parsedQuantity) || parsedQuantity < 1 || !Number.isFinite(parsedCost) || parsedCost < 0) return

    setLines((current) => [...current, { product: selectedProduct, quantity: parsedQuantity, unit_cost: parsedCost }])
    setProductId('')
    setQuantity('1')
    setUnitCost('')
  }

  function submitPurchase(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const formData = new FormData(event.currentTarget)
    createMutation.mutate({
      supplier_id: supplierId ? Number(supplierId) : undefined,
      notes: String(formData.get('notes')) || undefined,
      items: lines.map((line) => ({ product_id: line.product.id, quantity: line.quantity, unit_cost: line.unit_cost })),
    })
  }

  const rows = (purchasesQuery.data ?? []).map((purchase) => ({
    ...purchase,
    supplier_name: purchase.supplier?.name ?? 'Direct purchase',
    item_summary: purchase.items.map((item) => `${item.product_name} × ${item.quantity_received}`).join(', '),
    total_display: formatKes(Number(purchase.total)),
    ordered_date: new Date(purchase.ordered_at).toLocaleDateString(),
  }))

  return (
    <section className="module-page">
      <div className="mb-3 flex w-full flex-wrap items-end justify-between gap-4">
        <div>
          <p className="eyebrow">Procurement</p>
          <h1>Purchases</h1>
        </div>
        <Button variant="primary" icon={<Plus size={16} />} onClick={() => setIsCreateOpen((open) => !open)}>
          {isCreateOpen ? 'Close form' : 'Receive stock'}
        </Button>
      </div>

      {isCreateOpen && <Card className="w-full p-5">
        <form className="grid gap-5" onSubmit={submitPurchase}>
          <div className="grid gap-4 sm:grid-cols-2">
            <label className="grid gap-1 text-sm font-medium text-graphite">Supplier
              <select value={supplierId} onChange={(event) => setSupplierId(event.target.value)} className="rounded-lg border border-graphite/15 bg-white px-3 py-2.5">
                <option value="">Direct purchase</option>
                {(suppliersQuery.data ?? []).map((supplier) => <option key={supplier.id} value={supplier.id}>{supplier.name}</option>)}
              </select>
            </label>
            <label className="grid gap-1 text-sm font-medium text-graphite">Order note<input name="notes" maxLength={500} className="rounded-lg border border-graphite/15 px-3 py-2.5" placeholder="Optional reference or note" /></label>
          </div>

          <div className="grid items-end gap-3 sm:grid-cols-2 xl:grid-cols-[minmax(180px,1fr)_110px_150px_auto]">
            <label className="grid gap-1 text-sm font-medium text-graphite">Product
              <select value={productId} onChange={(event) => { setProductId(event.target.value); setUnitCost('') }} className="rounded-lg border border-graphite/15 bg-white px-3 py-2.5">
                <option value="">Select a product</option>
                {(productsQuery.data ?? []).map((product) => <option key={product.id} value={product.id}>{product.name} · {product.sku}</option>)}
              </select>
            </label>
            <label className="grid gap-1 text-sm font-medium text-graphite">Quantity<input type="number" min="1" step="1" value={quantity} onChange={(event) => setQuantity(event.target.value)} className="rounded-lg border border-graphite/15 px-3 py-2.5" /></label>
            <label className="grid gap-1 text-sm font-medium text-graphite">Unit cost (KES)<input type="number" min="0" step="0.01" value={unitCost} onChange={(event) => setUnitCost(event.target.value)} placeholder={selectedProduct?.cost_price ?? selectedProduct?.unit_price ?? '0.00'} className="rounded-lg border border-graphite/15 px-3 py-2.5" /></label>
            <Button type="button" variant="secondary" disabled={!selectedProduct} onClick={addLine} icon={<Plus size={15} />}>Add line</Button>
          </div>

          {lines.length > 0 && <div className="overflow-x-auto rounded-lg border border-graphite/10">
            <table className="w-full min-w-[500px] text-left text-sm">
              <thead className="bg-champagne-light text-graphite"><tr><th className="px-3 py-2.5">Product</th><th className="px-3 py-2.5">Quantity</th><th className="px-3 py-2.5">Unit cost</th><th className="px-3 py-2.5">Line total</th><th className="px-3 py-2.5"><span className="sr-only">Remove</span></th></tr></thead>
              <tbody>{lines.map((line, index) => <tr key={`${line.product.id}-${index}`} className="border-t border-graphite/10"><td className="px-3 py-2.5 font-medium">{line.product.name}</td><td className="px-3 py-2.5">{line.quantity}</td><td className="px-3 py-2.5">{formatKes(line.unit_cost)}</td><td className="px-3 py-2.5">{formatKes(line.quantity * line.unit_cost)}</td><td className="px-3 py-2.5"><button type="button" title="Remove line" aria-label={`Remove ${line.product.name}`} onClick={() => setLines((current) => current.filter((_, lineIndex) => lineIndex !== index))} className="grid h-8 w-8 place-items-center text-danger"><Trash2 size={15} /></button></td></tr>)}</tbody>
            </table>
          </div>}

          <div className="flex flex-wrap items-center justify-between gap-3 border-t border-graphite/10 pt-4">
            <p className="text-sm text-graphite/70">{lines.length} line{lines.length === 1 ? '' : 's'} <strong className="ml-2 text-graphite">Order total: {formatKes(orderTotal)}</strong></p>
            <div className="flex items-center gap-3">
              {createMutation.isError && <span role="alert" className="text-sm text-danger">Could not receive this order. Verify the supplier, products, and quantities.</span>}
              <Button type="submit" disabled={!lines.length || createMutation.isPending}>{createMutation.isPending ? 'Receiving...' : 'Receive purchase'}</Button>
            </div>
          </div>
        </form>
      </Card>}

      <Card className="w-full p-5">
        <div className="mb-4 flex items-center gap-2 text-sm font-semibold text-graphite"><ClipboardList size={17} /> Purchase history</div>
        {purchasesQuery.isError && <p role="alert" className="mb-4 text-sm text-danger">Purchases could not be loaded. Check the API connection and business context.</p>}
        {(suppliersQuery.isError || productsQuery.isError) && <p role="status" className="mb-4 text-sm text-warning">Supplier or product options are unavailable; check the API connection.</p>}
        <DataTable
          loading={purchasesQuery.isPending}
          rows={rows}
          emptyMessage="No purchase orders yet. Receive stock to create your first order."
          columns={[
            { header: 'Reference', accessor: 'reference_number' },
            { header: 'Date', accessor: 'ordered_date' },
            { header: 'Supplier', accessor: 'supplier_name' },
            { header: 'Items received', accessor: 'item_summary' },
            { header: 'Total', accessor: 'total_display' },
            { header: 'Status', accessor: 'status' },
          ]}
        />
      </Card>
    </section>
  )
}

function formatKes(amount: number): string {
  return new Intl.NumberFormat('en-KE', { style: 'currency', currency: 'KES', minimumFractionDigits: 2 }).format(amount)
}
