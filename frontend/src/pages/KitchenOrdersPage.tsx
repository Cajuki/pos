import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { createSale, getBusinessSettings, getRestaurantOrders, updateRestaurantOrderStatus, type RestaurantOrder } from '../api/commerce'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'
import { formatCurrency } from '../lib/utils'

const nextKitchenStatus: Partial<Record<RestaurantOrder['status'], 'preparing' | 'ready' | 'served'>> = {
  open: 'preparing',
  preparing: 'ready',
  ready: 'served',
}

export default function KitchenOrdersPage() {
  const queryClient = useQueryClient()
  const [error, setError] = useState('')
  const [selectedPaymentMethod, setSelectedPaymentMethod] = useState<'cash' | 'mpesa' | 'card' | 'bank' | 'credit' | null>(null)
  const ordersQuery = useQuery({ queryKey: ['restaurant-orders'], queryFn: getRestaurantOrders, refetchInterval: 15000 })
  const settingsQuery = useQuery({ queryKey: ['business-settings'], queryFn: getBusinessSettings })
  const refresh = async () => Promise.all([
    queryClient.invalidateQueries({ queryKey: ['restaurant-orders'] }),
    queryClient.invalidateQueries({ queryKey: ['inventory'] }),
    queryClient.invalidateQueries({ queryKey: ['products'] }),
    queryClient.invalidateQueries({ queryKey: ['sales'] }),
  ])
  const statusMutation = useMutation({
    mutationFn: ({ id, status }: { id: number; status: 'preparing' | 'ready' | 'served' | 'cancelled' }) => updateRestaurantOrderStatus(id, status),
    onSuccess: refresh,
    onError: () => setError('The order status could not be updated. Refresh and try again.'),
  })
  const paymentMutation = useMutation({
    mutationFn: (order: RestaurantOrder) => createSale({
      restaurant_order_id: order.id,
      payment_method: paymentMethod,
      items: order.items.map((item) => ({ product_id: item.product_id, quantity: item.quantity })),
    }),
    onSuccess: async () => {
      setError('')
      await refresh()
    },
    onError: () => setError('Payment failed. Check that the cash register is open and the order is still active.'),
  })
  const orders = ordersQuery.data ?? []
  const currency = localStorage.getItem('pos_currency') ?? 'KES'
  const paymentOptions = settingsQuery.data?.settings.payment_methods ?? []
  const paymentMethod = selectedPaymentMethod && paymentOptions.includes(selectedPaymentMethod)
    ? selectedPaymentMethod
    : paymentOptions.includes('cash') ? 'cash' : paymentOptions[0] ?? 'cash'

  return (
    <section className="module-page w-full">
      <div className="mb-6 flex items-center justify-between gap-4">
        <div><p className="eyebrow">Restaurant service</p><h1>Kitchen orders</h1><p className="text-sm text-graphite/60">Open tickets reserve available stock until payment or cancellation.</p></div>
        <Button variant="secondary" onClick={() => void ordersQuery.refetch()} disabled={ordersQuery.isFetching}>{ordersQuery.isFetching ? 'Refreshing...' : 'Refresh'}</Button>
      </div>
      {paymentOptions.length > 0 && <label className="mb-4 flex max-w-xs items-center gap-3 text-sm">Payment method
        <select value={paymentMethod} onChange={(event) => setSelectedPaymentMethod(event.target.value as typeof paymentMethod)} className="rounded-xl border border-graphite/10 bg-white px-3 py-2">
          {paymentOptions.map((method) => <option key={method} value={method}>{method === 'mpesa' ? 'M-Pesa' : method.charAt(0).toUpperCase() + method.slice(1)}</option>)}
        </select>
      </label>}
      {error && <p role="alert" className="mb-4 rounded-xl bg-red-50 p-3 text-sm text-red-700">{error}</p>}
      {ordersQuery.isError && <p role="alert" className="mb-4 rounded-xl bg-red-50 p-3 text-sm text-red-700">Kitchen orders could not be loaded.</p>}
      {ordersQuery.isPending ? <p>Loading kitchen tickets...</p> : orders.length === 0 ? (
        <Card className="p-8 text-center"><strong>No kitchen orders</strong><p className="mt-2 text-sm text-graphite/60">Send a dine-in, takeaway, or delivery order from the POS.</p></Card>
      ) : (
        <div className="grid gap-4 xl:grid-cols-2">
          {orders.map((order) => {
            const nextStatus = nextKitchenStatus[order.status]
            return (
              <Card key={order.id} className="p-5">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div><p className="eyebrow">TICKET #{order.id}</p><h2>{order.table_name || order.order_type.replace('_', ' ')}</h2><p className="text-sm text-graphite/60">{order.customer?.name ?? 'Walk-in'} · {new Date(order.created_at).toLocaleString()}</p></div>
                  <span className="rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold capitalize text-amber-800">{order.status}</span>
                </div>
                <ul className="my-4 divide-y divide-graphite/10">
                  {order.items.map((item) => <li key={item.id} className="flex justify-between py-2 text-sm"><span>{item.quantity} × {item.product.name}</span><strong>{formatCurrency(Number(item.unit_price) * item.quantity, currency)}</strong></li>)}
                </ul>
                <div className="flex flex-wrap gap-2">
                  {nextStatus && <Button variant="secondary" disabled={statusMutation.isPending} onClick={() => statusMutation.mutate({ id: order.id, status: nextStatus })}>Mark {nextStatus}</Button>}
                  {!['paid', 'cancelled'].includes(order.status) && <Button variant="primary" disabled={paymentMutation.isPending || settingsQuery.isPending || settingsQuery.isError || !paymentOptions.length} onClick={() => { setError(''); paymentMutation.mutate(order) }}>{paymentMutation.isPending ? 'Processing...' : `Pay ${paymentMethod === 'mpesa' ? 'M-Pesa' : paymentMethod} & close`}</Button>}
                  {!['paid', 'cancelled'].includes(order.status) && <Button variant="secondary" disabled={statusMutation.isPending} onClick={() => statusMutation.mutate({ id: order.id, status: 'cancelled' })}>Cancel order</Button>}
                  {order.sale && <span className="self-center text-sm text-emerald-700">Paid · {order.sale.receipt_number}</span>}
                </div>
              </Card>
            )
          })}
        </div>
      )}
    </section>
  )
}
