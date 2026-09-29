import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { adjustStock, getInventory, getStockMovements } from '../api/commerce'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'
import { DataTable } from '../components/ui/DataTable'

export default function InventoryPage() {
  const queryClient = useQueryClient()
  const [isAdjustmentOpen, setIsAdjustmentOpen] = useState(false)
  const inventoryQuery = useQuery({ queryKey: ['inventory'], queryFn: getInventory })
  const movementsQuery = useQuery({ queryKey: ['stock-movements'], queryFn: getStockMovements })
  const adjustmentMutation = useMutation({
    mutationFn: adjustStock,
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['inventory'] }),
        queryClient.invalidateQueries({ queryKey: ['stock-movements'] }),
        queryClient.invalidateQueries({ queryKey: ['products'] }),
      ])
      setIsAdjustmentOpen(false)
    },
  })

  function submitAdjustment(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const formData = new FormData(event.currentTarget)
    adjustmentMutation.mutate({
      product_id: Number(formData.get('product_id')),
      quantity_change: Number(formData.get('quantity_change')),
      reason: String(formData.get('reason')),
    })
  }

  const rows = (inventoryQuery.data ?? []).map((item) => ({
    ...item,
    status: item.quantity_on_hand === 0 ? 'Out of stock' : item.is_low_stock ? 'Low stock' : 'Healthy',
  }))
  const movementRows = (movementsQuery.data ?? []).map((movement) => ({
    ...movement,
    product_name: movement.product.name,
    date: new Date(movement.created_at).toLocaleString(),
  }))

  return (
    <section className="module-page">
      <div className="mb-6 flex items-center justify-between gap-4">
        <div>
          <p className="eyebrow">Operations</p>
          <h1>Inventory</h1>
        </div>
        <Button variant="primary" onClick={() => setIsAdjustmentOpen((open) => !open)}>
          {isAdjustmentOpen ? 'Close form' : 'Adjust stock'}
        </Button>
      </div>

      {isAdjustmentOpen && (
        <Card className="mb-5 p-5">
          <form className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" onSubmit={submitAdjustment}>
            <label className="grid gap-1 text-sm font-medium text-graphite">Product
              <select required name="product_id" className="rounded-xl border border-graphite/10 bg-white px-3 py-2.5">
                {(inventoryQuery.data ?? []).map((item) => <option key={item.product_id} value={item.product_id}>{item.name} ({item.quantity_on_hand} on hand)</option>)}
              </select>
            </label>
            <label className="grid gap-1 text-sm font-medium text-graphite">Quantity change<input required name="quantity_change" type="number" step="1" min="-2147483648" max="2147483647" className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
            <label className="grid gap-1 text-sm font-medium text-graphite">Reason<input required name="reason" maxLength={255} className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
            <div className="flex items-center gap-3 sm:col-span-2 xl:col-span-3">
              <Button type="submit" disabled={adjustmentMutation.isPending || !inventoryQuery.data?.length}>{adjustmentMutation.isPending ? 'Saving...' : 'Record adjustment'}</Button>
              {adjustmentMutation.isError && <span className="text-sm text-danger">Adjustment rejected. Check the quantity and available stock.</span>}
            </div>
          </form>
        </Card>
      )}

      <Card className="p-5">
        <div className="mb-4 flex items-center justify-between">
          <h2 className="text-xl font-bold text-graphite">Stock overview</h2>
          <span className="text-sm text-graphite/60">{inventoryQuery.data?.length ?? 0} products</span>
        </div>

        {inventoryQuery.isError && <p role="alert" className="mb-4 text-sm text-danger">Inventory could not be loaded. Check the API connection and business context.</p>}
        <DataTable
          loading={inventoryQuery.isPending}
          rows={rows}
          emptyMessage="No stock records yet. Create a product to start inventory tracking."
          columns={[
            { header: 'Product', accessor: 'name' },
            { header: 'SKU', accessor: 'sku' },
            { header: 'Selling price', accessor: 'unit_price', render: (item) => `KES ${item.unit_price}` },
            { header: 'On hand', accessor: 'quantity_on_hand' },
            { header: 'Reorder level', accessor: 'reorder_level' },
            { header: 'Status', accessor: 'status' },
          ]}
        />
      </Card>

      <Card className="mt-6 p-5">
        <div className="mb-4 flex items-center justify-between">
          <h2 className="text-xl font-bold text-graphite">Stock movements</h2>
          <span className="text-sm text-graphite/60">{movementsQuery.data?.length ?? 0} loaded</span>
        </div>
        {movementsQuery.isError && <p role="alert" className="mb-4 text-sm text-danger">Stock movements could not be loaded.</p>}
        <DataTable
          loading={movementsQuery.isPending}
          rows={movementRows}
          emptyMessage="No stock movements recorded yet."
          columns={[
            { header: 'Date', accessor: 'date' },
            { header: 'Product', accessor: 'product_name' },
            { header: 'Type', accessor: 'type' },
            { header: 'Change', accessor: 'quantity_change' },
            { header: 'Before', accessor: 'quantity_before' },
            { header: 'After', accessor: 'quantity_after' },
            { header: 'Reason', accessor: 'reason' },
          ]}
        />
      </Card>
    </section>
  )
}
