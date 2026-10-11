import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import axios from 'axios'
import { useState, type FormEvent } from 'react'
import { createCashRefund, getBusinessSettings, getSales, type Sale } from '../api/commerce'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'
import { DataTable } from '../components/ui/DataTable'
import { Input } from '../components/ui/Input'
import { Modal } from '../components/ui/Modal'
import { formatCurrency } from '../lib/utils'

export default function SalesPage({ canRefund }: { canRefund: boolean }) {
  const queryClient = useQueryClient()
  const [selectedSale, setSelectedSale] = useState<Sale | null>(null)
  const [quantities, setQuantities] = useState<Record<number, number>>({})
  const [barcodes, setBarcodes] = useState<Record<number, string[]>>({})
  const [reason, setReason] = useState('')
  const [formError, setFormError] = useState('')
  const [successMessage, setSuccessMessage] = useState('')
  const closeRefund = () => {
    setSelectedSale(null)
    setQuantities({})
    setBarcodes({})
    setReason('')
    setFormError('')
  }
  const salesQuery = useQuery({ queryKey: ['sales'], queryFn: getSales })
  const settingsQuery = useQuery({ queryKey: ['business-settings'], queryFn: getBusinessSettings })
  const currency = settingsQuery.data?.currency ?? 'KES'
  const refundMutation = useMutation({
    mutationFn: ({ saleId, payload }: {
      saleId: number
      payload: { reason: string; items: Array<{ sale_item_id: number; quantity: number; barcodes?: string[] }> }
    }) => createCashRefund(saleId, payload),
    onSuccess: async (refund) => {
      await queryClient.invalidateQueries({ queryKey: ['sales'] })
      setSuccessMessage(`Cash refund ${refund.reference_number} recorded for ${formatCurrency(Number(refund.amount), currency)}.`)
      closeRefund()
    },
  })
  function submitRefund(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!selectedSale) return
    const items = selectedSale.items
      .filter((item) => (quantities[item.id] ?? 0) > 0)
      .map((item) => ({
        sale_item_id: item.id,
        quantity: quantities[item.id],
        ...(item.barcodes?.length ? { barcodes: barcodes[item.id] ?? [] } : {}),
      }))
    if (items.length === 0) {
      setFormError('Choose at least one item and quantity to return.')
      return
    }
    if (items.some((item) => item.barcodes && item.barcodes.length !== item.quantity)) {
      setFormError('Select the exact serial numbers being physically returned.')
      return
    }
    setFormError('')
    refundMutation.mutate({ saleId: selectedSale.id, payload: { reason: reason.trim(), items } })
  }

  function refundError(): string {
    if (!axios.isAxiosError(refundMutation.error)) return 'The refund could not be recorded. Try again.'
    const data = refundMutation.error.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined
    return Object.values(data?.errors ?? {})[0]?.[0] ?? data?.message ?? 'The refund could not be recorded. Try again.'
  }

  const sales = (salesQuery.data ?? []).map((sale) => ({
    ...sale,
    invoice: sale.receipt_number,
    customer_name: sale.customer?.name ?? 'Walk-in',
    channel: sale.payment_method === 'credit' ? 'Store credit' : sale.payment_method.toUpperCase(),
    discount_label: Number(sale.discount_amount) > 0 ? formatCurrency(Number(sale.discount_amount), currency) : '—',
    total_label: formatCurrency(Number(sale.total), currency),
    refunded_label: Number(sale.refunded_total) > 0 ? formatCurrency(Number(sale.refunded_total), currency) : '—',
    net_total_label: formatCurrency(Number(sale.total) - Number(sale.refunded_total), currency),
    date: new Date(sale.created_at).toLocaleString(),
  }))

  return (
    <section className="module-page">
      <div className="mb-6">
        <p className="eyebrow">Revenue</p>
        <h1>Sales</h1>
      </div>

      {successMessage && <div role="status" className="mb-4 flex items-center justify-between rounded-xl border border-lime-dark/20 bg-lime/20 px-4 py-3 text-sm text-graphite">
        <span>{successMessage}</span>
        <button type="button" aria-label="Dismiss refund confirmation" onClick={() => setSuccessMessage('')} className="ml-4 font-semibold">Dismiss</button>
      </div>}

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
            { header: 'Sale total', accessor: 'total_label' },
            { header: 'Refunded', accessor: 'refunded_label' },
            { header: 'Net', accessor: 'net_total_label' },
            { header: 'Status', accessor: 'status' },
            { header: 'Actions', accessor: 'id', render: (sale) => canRefund && sale.status === 'paid' && sale.payment_method === 'cash' && sale.items.some((item) => item.refunded_quantity < item.quantity)
              ? <Button size="sm" variant="outline" onClick={() => { setSuccessMessage(''); setSelectedSale(sale); setQuantities({}); setBarcodes({}) }}>Refund</Button>
              : <span className="text-xs text-graphite/45">—</span> },
          ]}
        />
      </Card>

      <Modal
        isOpen={Boolean(selectedSale)}
        onClose={closeRefund}
        title="Record cash refund"
        subtitle={selectedSale ? `${selectedSale.receipt_number} · Original total ${formatCurrency(Number(selectedSale.total), currency)}` : undefined}
        maxWidth="2xl"
      >
        {selectedSale && <form className="space-y-5" onSubmit={submitRefund}>
          <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
            This immediately records cash leaving the open register and returns items to stock. Electronic and store-credit refunds are not supported here.
          </div>
          <div className="space-y-3">
            {selectedSale.items.map((item) => {
              const remaining = Math.max(0, item.quantity - item.refunded_quantity)
              const quantity = quantities[item.id] ?? 0
              return <div key={item.id} className="rounded-xl border border-graphite/10 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div>
                    <p className="font-semibold text-graphite">{item.product_name}</p>
                    <p className="mt-0.5 text-xs text-graphite/55">{item.sku} · {remaining} unit(s) available to refund</p>
                  </div>
                  <Input
                    aria-label={`Quantity to refund for ${item.product_name}`}
                    type="number"
                    min={0}
                    max={remaining}
                    step={1}
                    value={quantity}
                    onChange={(event) => setQuantities((values) => ({ ...values, [item.id]: Math.min(remaining, Math.max(0, Number(event.target.value))) }))}
                    className="w-24"
                  />
                </div>
                {item.barcodes?.length && quantity > 0 ? <fieldset className="mt-3 space-y-2">
                  <legend className="text-xs font-semibold text-graphite/70">Scan/confirm returned serial numbers</legend>
                  {item.barcodes.map((barcode) => <label key={barcode} className="flex items-center gap-2 text-xs text-graphite/70">
                    <input
                      type="checkbox"
                      checked={(barcodes[item.id] ?? []).includes(barcode)}
                      onChange={(event) => setBarcodes((values) => ({
                        ...values,
                        [item.id]: event.target.checked
                          ? [...(values[item.id] ?? []), barcode]
                          : (values[item.id] ?? []).filter((value) => value !== barcode),
                      }))}
                      className="h-4 w-4 accent-lime-dark"
                    />
                    {barcode}
                  </label>)}
                </fieldset> : null}
              </div>
            })}
          </div>
          <label className="block text-sm font-medium text-graphite">
            Reason for refund
            <textarea
              required
              minLength={3}
              maxLength={255}
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              rows={3}
              placeholder="Describe why the items are being returned"
              className="mt-2 w-full rounded-xl border border-graphite/10 bg-white px-4 py-3 text-sm outline-none transition focus:border-lime focus:ring-2 focus:ring-lime/30"
            />
          </label>
          {(formError || refundMutation.isError) && <p role="alert" className="text-sm text-danger">{formError || refundError()}</p>}
          <div className="flex justify-end gap-3 border-t border-graphite/10 pt-4">
            <Button type="button" variant="secondary" onClick={closeRefund}>Cancel</Button>
            <Button type="submit" variant="danger" disabled={refundMutation.isPending}>
              {refundMutation.isPending ? 'Recording…' : 'Confirm cash refund'}
            </Button>
          </div>
        </form>}
      </Modal>
    </section>
  )
}
