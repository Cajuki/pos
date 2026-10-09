import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useDeferredValue, useState, type FormEvent } from 'react'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'
import { DataTable } from '../components/ui/DataTable'
import { createProduct, getBusinessSettings, getProducts } from '../api/commerce'
import { formatCurrency } from '../lib/utils'

export default function ProductsPage() {
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [isCreateOpen, setIsCreateOpen] = useState(false)
  const deferredSearch = useDeferredValue(search)
  const settingsQuery = useQuery({ queryKey: ['business-settings'], queryFn: getBusinessSettings })
  const productsQuery = useQuery({ queryKey: ['products', deferredSearch], queryFn: () => getProducts(deferredSearch) })
  const currency = settingsQuery.data?.currency ?? 'KES'
  const createMutation = useMutation({
    mutationFn: createProduct,
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['products'] })
      await queryClient.invalidateQueries({ queryKey: ['inventory'] })
      setIsCreateOpen(false)
    },
  })

  function submitProduct(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const formData = new FormData(event.currentTarget)
    const sku = String(formData.get('sku') ?? '').trim()
    const unitPrice = Number(formData.get('unit_price'))
    const costPrice = formData.get('cost_price')

    if (!sku || !Number.isFinite(unitPrice) || unitPrice < 0) {
      return
    }

    createMutation.mutate({
      sku,
      barcode: String(formData.get('barcode') ?? '').trim() || undefined,
      name: String(formData.get('name') ?? '').trim(),
      category: String(formData.get('category') ?? '').trim() || undefined,
      unit_price: unitPrice,
      cost_price: costPrice === null || String(costPrice).trim() === '' ? undefined : Number(costPrice),
      quantity_on_hand: Number(formData.get('quantity_on_hand') || 0),
      reorder_level: Number(formData.get('reorder_level') || 0),
    })
  }

  const rows = (productsQuery.data ?? []).map((product) => ({
    ...product,
    price: formatCurrency(Number(product.unit_price), currency),
    stock_status: product.quantity_on_hand <= 0 ? 'Out of stock' : product.quantity_on_hand <= product.reorder_level ? 'Low stock' : 'In stock',
  }))

  return (
    <section className="module-page w-full">
      <div className="mb-6 flex w-full items-center justify-between gap-4">
        <div>
          <p className="eyebrow">Catalog</p>
          <h1>Products</h1>
        </div>
        <Button variant="primary" onClick={() => setIsCreateOpen((open) => !open)}>
          {isCreateOpen ? 'Close form' : 'Add product'}
        </Button>
      </div>

      {isCreateOpen && (
        <Card className="mb-5 w-full p-5">
          <form className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" onSubmit={submitProduct}>
            <label className="grid gap-1 text-sm font-medium text-graphite">SKU<input required name="sku" maxLength={64} title="Use letters, numbers, and hyphens only" className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
            <label className="grid gap-1 text-sm font-medium text-graphite">Barcode (scan or type)<input name="barcode" maxLength={100} autoFocus placeholder="Scan barcode" onKeyDown={(event) => { if (event.key === 'Enter') event.preventDefault() }} className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
            <label className="grid gap-1 text-sm font-medium text-graphite">Product name<input required name="name" className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
            <label className="grid gap-1 text-sm font-medium text-graphite">Category<input name="category" className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
            <label className="grid gap-1 text-sm font-medium text-graphite">Selling price ({currency})<input required name="unit_price" type="number" min="0" step="1" inputMode="numeric" className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
            <label className="grid gap-1 text-sm font-medium text-graphite">Cost price ({currency})<input name="cost_price" type="number" min="0" step="1" inputMode="numeric" className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
            <label className="grid gap-1 text-sm font-medium text-graphite">Initial quantity on hand<input name="quantity_on_hand" type="number" min="0" max="2147483647" step="1" defaultValue="0" className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
            <label className="grid gap-1 text-sm font-medium text-graphite">Reorder level<input name="reorder_level" type="number" min="0" step="1" defaultValue="0" className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
            <div className="flex items-center gap-3 sm:col-span-2 xl:col-span-3">
              <Button type="submit" disabled={createMutation.isPending}>{createMutation.isPending ? 'Saving...' : 'Save product'}</Button>
              {createMutation.isError && <span className="text-sm text-danger">Could not save product. Check the SKU and price.</span>}
            </div>
          </form>
        </Card>
      )}

      <Card className="w-full p-5">
        <div className="mb-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
          <input value={search} onChange={(event) => setSearch(event.target.value)} className="w-full rounded-xl border border-graphite/10 px-4 py-3 text-sm md:max-w-sm" placeholder="Search products" />
        </div>

        {productsQuery.isError && <p role="alert" className="mb-4 text-sm text-danger">Products could not be loaded. Check the API connection and business context.</p>}
        <DataTable
          loading={productsQuery.isPending}
          rows={rows}
          emptyMessage="No products found. Add a product to begin tracking inventory."
          columns={[
            { header: 'Product', accessor: 'name' },
            { header: 'SKU', accessor: 'sku' },
            { header: 'Barcode', accessor: 'barcode' },
            { header: 'Category', accessor: 'category' },
            { header: 'Selling Price', accessor: 'price' },
            { header: 'Stock', accessor: 'quantity_on_hand' },
            { header: 'Status', accessor: 'stock_status' },
          ]}
        />
      </Card>
    </section>
  )
}
