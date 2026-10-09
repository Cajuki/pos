import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { adjustStock, getBusinessSettings, getInventory, getStockMovements, receiveBarcodeStock } from '../api/commerce'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'
import { DataTable } from '../components/ui/DataTable'
import { formatCurrency } from '../lib/utils'

export default function InventoryPage() {
  const queryClient = useQueryClient()
  const [isAdjustmentOpen, setIsAdjustmentOpen] = useState(false)
  const [barcodeInput, setBarcodeInput] = useState('')
  const [barcodeProductId, setBarcodeProductId] = useState('')
  const [receivedCount, setReceivedCount] = useState(0)
  const inventoryQuery = useQuery({ queryKey: ['inventory'], queryFn: getInventory })
  const settingsQuery = useQuery({ queryKey: ['business-settings'], queryFn: getBusinessSettings })
  const movementsQuery = useQuery({ queryKey: ['stock-movements'], queryFn: getStockMovements })
  const currency = settingsQuery.data?.currency ?? 'KES'
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
  const barcodeMutation = useMutation({
    mutationFn: receiveBarcodeStock,
    onSuccess: async () => {
      setBarcodeInput('')
      setReceivedCount((count) => count + 1)
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['inventory'] }),
        queryClient.invalidateQueries({ queryKey: ['stock-movements'] }),
        queryClient.invalidateQueries({ queryKey: ['products'] }),
        queryClient.invalidateQueries({ queryKey: ['pos-products'] }),
      ])
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

  function submitBarcodeReceipt(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!barcodeProductId || !barcodeInput.trim()) return
    barcodeMutation.mutate({ product_id: Number(barcodeProductId), barcode: barcodeInput.trim() })
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

      <Card className="mb-5 p-5">
        <div className="mb-4">
          <h2 className="text-xl font-bold text-graphite">Receive individually barcoded stock</h2>
          <p className="mt-1 text-sm text-graphite/65">Select one product, then scan each unit barcode. Every successful scan adds one unit to that product's stock.</p>
        </div>
        <form className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" onSubmit={submitBarcodeReceipt}>
          <label className="grid gap-1 text-sm font-medium text-graphite">Product
            <select required value={barcodeProductId} onChange={(event) => { setBarcodeProductId(event.target.value); setReceivedCount(0); barcodeMutation.reset() }} className="rounded-xl border border-graphite/10 bg-white px-3 py-2.5">
              <option value="">Choose a product</option>
              {(inventoryQuery.data ?? []).map((item) => <option key={item.product_id} value={item.product_id}>{item.name} ({item.quantity_on_hand} on hand{item.barcode_tracking_enabled ? ', tracked' : ''})</option>)}
            </select>
          </label>
          <label className="grid gap-1 text-sm font-medium text-graphite">Unit barcode
            <input required autoComplete="off" value={barcodeInput} onChange={(event) => { setBarcodeInput(event.target.value); barcodeMutation.reset() }} className="rounded-xl border border-graphite/10 px-3 py-2.5" placeholder="Scan a unique barcode and press Enter" />
          </label>
          <div className="flex items-end gap-3">
            <Button type="submit" disabled={!barcodeProductId || !barcodeInput.trim() || barcodeMutation.isPending}>{barcodeMutation.isPending ? 'Recording...' : 'Receive scanned unit'}</Button>
            {receivedCount > 0 && <span className="pb-2 text-sm text-graphite/65">{receivedCount} scanned this session</span>}
          </div>
          {barcodeMutation.isError && <p role="alert" className="text-sm text-danger sm:col-span-2 xl:col-span-3">Barcode could not be received. It may already exist, or the product's existing stock must be adjusted to zero before tracking starts.</p>}
          {barcodeMutation.isSuccess && <p role="status" className="text-sm text-emerald-700 sm:col-span-2 xl:col-span-3">Unit received. Scan the next barcode.</p>}
        </form>
      </Card>

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
            { header: 'Selling price', accessor: 'unit_price', render: (item) => formatCurrency(Number(item.unit_price), currency) },
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
