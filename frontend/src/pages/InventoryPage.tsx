import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import axios from 'axios'
import { useState, type FormEvent } from 'react'
import { adjustStock, getBusinessSettings, getInventory, getInventoryBatches, getStockMovements, receiveBarcodeStock, receiveInventoryBatch } from '../api/commerce'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'
import { DataTable } from '../components/ui/DataTable'
import { formatCurrency } from '../lib/utils'

function getAdjustmentError(error: unknown): string {
  if (axios.isAxiosError(error)) {
    const response = error.response?.data as { message?: unknown; errors?: Record<string, unknown> } | undefined
    const firstValidationError = response?.errors
      ? Object.values(response.errors).flatMap((messages) => Array.isArray(messages) ? messages : [messages])[0]
      : undefined

    if (typeof firstValidationError === 'string') return firstValidationError
    if (typeof response?.message === 'string' && error.response?.status === 422) return response.message
    if (!error.response) return 'Could not contact the server. Check your connection and try again.'
  }

  return 'Adjustment failed. Check the quantity, reason, and available stock, then try again.'
}

export default function InventoryPage() {
  const queryClient = useQueryClient()
  const [isAdjustmentOpen, setIsAdjustmentOpen] = useState(false)
  const [barcodeInput, setBarcodeInput] = useState('')
  const [barcodeProductId, setBarcodeProductId] = useState('')
  const [receivedCount, setReceivedCount] = useState(0)
  const [batchProductId, setBatchProductId] = useState('')
  const inventoryQuery = useQuery({ queryKey: ['inventory'], queryFn: getInventory })
  const settingsQuery = useQuery({ queryKey: ['business-settings'], queryFn: getBusinessSettings })
  const movementsQuery = useQuery({ queryKey: ['stock-movements'], queryFn: getStockMovements })
  const isPharmacy = settingsQuery.data?.industry === 'pharmacy'
  const batchesQuery = useQuery({ queryKey: ['inventory-batches'], queryFn: getInventoryBatches, enabled: isPharmacy })
  const expirySummary = batchesQuery.data?.expirySummary
  const currency = settingsQuery.data?.currency ?? 'KES'
  const individuallyTrackedProducts = (inventoryQuery.data ?? []).filter((item) => item.barcode_tracking_enabled)
  const batchTrackedProducts = (inventoryQuery.data ?? []).filter((item) => item.batch_tracking_enabled)
  const adjustableProducts = (inventoryQuery.data ?? []).filter((item) => !item.barcode_tracking_enabled && !item.batch_tracking_enabled)
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
  const batchMutation = useMutation({
    mutationFn: receiveInventoryBatch,
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['inventory'] }),
        queryClient.invalidateQueries({ queryKey: ['inventory-batches'] }),
        queryClient.invalidateQueries({ queryKey: ['stock-movements'] }),
        queryClient.invalidateQueries({ queryKey: ['products'] }),
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

  function submitBatchReceipt(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const formData = new FormData(event.currentTarget)
    batchMutation.mutate({
      product_id: Number(formData.get('product_id')),
      lot_number: String(formData.get('lot_number')).trim(),
      expires_on: String(formData.get('expires_on')),
      quantity: Number(formData.get('quantity')),
      unit_cost: String(formData.get('unit_cost')).trim() ? Number(formData.get('unit_cost')) : undefined,
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
    <section className="module-page inventory-page">
      <div className="inventory-header mb-6 flex items-center justify-between gap-4">
        <div>
          <p className="eyebrow">Operations</p>
          <h1>Inventory</h1>
        </div>
        <Button variant="primary" onClick={() => setIsAdjustmentOpen((open) => !open)}>
          {isAdjustmentOpen ? 'Close form' : 'Adjust stock'}
        </Button>
      </div>

      <Card className="receive-stock-card inventory-unit-receipt mb-5 p-5">
        <div className="inventory-section-heading mb-4">
          <h2 className="text-xl font-bold text-graphite">Receive individually tracked units</h2>
          <p className="mt-1 text-sm text-graphite/65">For products set to unique barcodes per physical unit, scan each unit once. Every successful scan adds one unit; ordinary shared-barcode products use quantity adjustments or Purchases.</p>
        </div>
        <form className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" onSubmit={submitBarcodeReceipt}>
          <label className="grid gap-1 text-sm font-medium text-graphite">Product
            <select required value={barcodeProductId} onChange={(event) => { setBarcodeProductId(event.target.value); setReceivedCount(0); barcodeMutation.reset() }} className="rounded-xl border border-graphite/10 bg-white px-3 py-2.5">
              <option value="">Choose a product</option>
              {individuallyTrackedProducts.map((item) => <option key={item.product_id} value={item.product_id}>{item.name} ({item.quantity_on_hand} serialized units)</option>)}
            </select>
          </label>
          <label className="grid gap-1 text-sm font-medium text-graphite">Unique unit barcode
            <input required autoComplete="off" value={barcodeInput} onChange={(event) => { setBarcodeInput(event.target.value); barcodeMutation.reset() }} className="rounded-xl border border-graphite/10 px-3 py-2.5" placeholder="Scan one unique unit barcode" />
          </label>
          <div className="flex items-end gap-3">
            <Button type="submit" disabled={!individuallyTrackedProducts.length || !barcodeProductId || !barcodeInput.trim() || barcodeMutation.isPending}>{barcodeMutation.isPending ? 'Recording...' : 'Receive scanned unit'}</Button>
            {receivedCount > 0 && <span className="pb-2 text-sm text-graphite/65">{receivedCount} scanned this session</span>}
          </div>
          {!inventoryQuery.isPending && !individuallyTrackedProducts.length && <p className="text-sm text-graphite/60 sm:col-span-2 xl:col-span-3">No products use unique per-unit barcodes yet. Choose that tracking mode when adding a serialized product.</p>}
          {barcodeMutation.isError && <p role="alert" className="text-sm text-danger sm:col-span-2 xl:col-span-3">Barcode could not be received. It may already exist, or the product's existing stock must be adjusted to zero before tracking starts.</p>}
          {barcodeMutation.isSuccess && <p role="status" className="text-sm text-emerald-700 sm:col-span-2 xl:col-span-3">Unit received. Scan the next barcode.</p>}
        </form>
      </Card>

      {isPharmacy && <Card className="receive-stock-card pharmacy-stock-card inventory-batch-receipt mb-5 p-5">
        <div className="inventory-section-heading mb-4">
          <h2 className="text-xl font-bold text-graphite">Receive pharmacy batch</h2>
          <p className="mt-1 text-sm text-graphite/65">Record the supplier lot and expiry date. Checkout excludes expired lots and consumes valid stock by earliest expiry (FEFO).</p>
        </div>
        <form className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5" onSubmit={submitBatchReceipt}>
          <label className="grid gap-1 text-sm font-medium text-graphite">Product
            <select required name="product_id" value={batchProductId} onChange={(event) => setBatchProductId(event.target.value)} className="rounded-xl border border-graphite/10 bg-white px-3 py-2.5">
              <option value="">Choose product</option>
              {batchTrackedProducts.map((item) => <option key={item.product_id} value={item.product_id}>{item.name} ({item.quantity_on_hand} on hand)</option>)}
            </select>
          </label>
          <label className="grid gap-1 text-sm font-medium text-graphite">Lot number<input required name="lot_number" maxLength={100} className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
          <label className="grid gap-1 text-sm font-medium text-graphite">Expiry date<input required name="expires_on" type="date" min={new Date().toISOString().slice(0, 10)} className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
          <label className="grid gap-1 text-sm font-medium text-graphite">Quantity<input required name="quantity" type="number" min="1" max="2147483647" step="1" className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
          <label className="grid gap-1 text-sm font-medium text-graphite">Unit cost ({currency})<input name="unit_cost" type="number" min="0" step="0.01" className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
          <div className="flex items-center gap-3 sm:col-span-2 xl:col-span-5">
            <Button type="submit" disabled={!batchProductId || batchMutation.isPending}>{batchMutation.isPending ? 'Receiving...' : 'Receive batch'}</Button>
            {!batchTrackedProducts.length && <span className="text-sm text-graphite/60">Create a product with pharmacy batch tracking enabled first.</span>}
            {batchMutation.isError && <span role="alert" className="text-sm text-danger">{getAdjustmentError(batchMutation.error)}</span>}
            {batchMutation.isSuccess && <span role="status" className="text-sm text-emerald-700">Batch received and inventory updated.</span>}
          </div>
        </form>
        {batchesQuery.isError && <p role="alert" className="mt-4 text-sm text-danger">Pharmacy batches could not be loaded.</p>}
        <div className="mt-5">
          <div className="mb-4 grid gap-3 sm:grid-cols-2">
            <Card className="border border-danger/20 bg-danger/5 p-4">
              <p className="text-xs font-semibold uppercase tracking-wide text-graphite/60">Expired units</p>
              <p className="mt-1 text-2xl font-bold text-danger">{expirySummary?.expired_units ?? '—'}</p>
              <p className="mt-1 text-xs text-graphite/60">Excluded from checkout until returned or disposed.</p>
            </Card>
            <Card className="border border-amber-300 bg-amber-50 p-4">
              <p className="text-xs font-semibold uppercase tracking-wide text-graphite/60">Expiring in the next 30 days</p>
              <p className="mt-1 text-2xl font-bold text-amber-800">{expirySummary?.expiring_units ?? '—'}</p>
              <p className="mt-1 text-xs text-graphite/60">Units remaining in batches that need attention.</p>
            </Card>
          </div>
          <DataTable
            loading={batchesQuery.isPending}
            rows={(batchesQuery.data?.batches ?? []).map((batch) => {
              const daysUntilExpiry = Math.floor(
                (Date.parse(`${batch.expires_on.slice(0, 10)}T00:00:00Z`) - Date.parse(`${expirySummary?.as_of ?? new Date().toISOString().slice(0, 10)}T00:00:00Z`))
                / 86_400_000,
              )

              return {
                ...batch,
                product_name: batch.product.name,
                expiry: batch.expires_on.slice(0, 10),
                days_remaining: batch.quantity_remaining === 0
                  ? '—'
                  : daysUntilExpiry < 0
                    ? `${Math.abs(daysUntilExpiry)} days overdue`
                    : daysUntilExpiry === 0
                      ? 'Today'
                      : `${daysUntilExpiry} days`,
                status: batch.quantity_remaining === 0
                  ? 'Depleted'
                  : daysUntilExpiry < 0
                    ? 'Expired'
                    : daysUntilExpiry === 0
                      ? 'Expires today'
                      : daysUntilExpiry <= 30
                        ? 'Expiring soon'
                        : 'Usable',
              }
            })}
            emptyMessage="No pharmacy batches received yet."
            columns={[
              { header: 'Product', accessor: 'product_name' },
              { header: 'Lot number', accessor: 'lot_number' },
              { header: 'Expires', accessor: 'expiry' },
              { header: 'Received', accessor: 'quantity_received' },
              { header: 'Remaining', accessor: 'quantity_remaining' },
              { header: 'Time remaining', accessor: 'days_remaining' },
              { header: 'Status', accessor: 'status' },
            ]}
          />
        </div>
      </Card>}

      {isAdjustmentOpen && (
        <Card className="inventory-adjustment-card mb-5 p-5">
          <form className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" onSubmit={submitAdjustment}>
            <label className="grid gap-1 text-sm font-medium text-graphite">Product
              <select required name="product_id" className="rounded-xl border border-graphite/10 bg-white px-3 py-2.5">
                {adjustableProducts.map((item) => <option key={item.product_id} value={item.product_id}>{item.name} ({item.quantity_on_hand} on hand)</option>)}
              </select>
            </label>
            <label className="grid gap-1 text-sm font-medium text-graphite">Quantity change<input required name="quantity_change" type="number" step="1" min="-2147483648" max="2147483647" placeholder="e.g. 5 to add, -2 to remove" className="rounded-xl border border-graphite/10 px-3 py-2.5" /><span className="text-xs font-normal text-graphite/60">Enter a change, not a new total. Use a positive number to add stock or a negative number to remove it. Stock cannot go below zero.</span></label>
            <label className="grid gap-1 text-sm font-medium text-graphite">Reason<input required name="reason" maxLength={255} className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
            {!inventoryQuery.isPending && !adjustableProducts.length && <p className="text-sm text-graphite/60 sm:col-span-2 xl:col-span-3">All current products use unique per-unit barcodes. Receive or sell their units by scanning each barcode; quantity adjustments are only for shared-barcode products.</p>}
            <div className="flex items-center gap-3 sm:col-span-2 xl:col-span-3">
              <Button type="submit" disabled={adjustmentMutation.isPending || !adjustableProducts.length}>{adjustmentMutation.isPending ? 'Saving...' : 'Record adjustment'}</Button>
              {adjustmentMutation.isError && <span role="alert" className="text-sm text-danger">{getAdjustmentError(adjustmentMutation.error)}</span>}
            </div>
          </form>
        </Card>
      )}

      <Card className="inventory-table-card p-5">
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
            { header: 'Barcode', accessor: 'barcode', render: (item) => item.barcode_tracking_enabled ? 'Unique per unit' : item.barcode || '—' },
            { header: 'Selling price', accessor: 'unit_price', render: (item) => formatCurrency(Number(item.unit_price), currency) },
            { header: 'On hand', accessor: 'quantity_on_hand' },
            { header: 'Reorder level', accessor: 'reorder_level' },
            { header: 'Status', accessor: 'status' },
          ]}
        />
      </Card>

      <Card className="inventory-table-card mt-6 p-5">
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
