import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { createRestaurantTable, createSale, getBranches, getBusinessSettings, getCashRegisters, getRestaurantOrders, getRestaurantTables, updateRestaurantOrderStatus, updateRestaurantTableStatus, type RestaurantOrder } from '../api/commerce'
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
  const [selectedBranchId, setSelectedBranchId] = useState<number | undefined>()
  const [tableName, setTableName] = useState('')
  const [tableArea, setTableArea] = useState('')
  const [tableCapacity, setTableCapacity] = useState('2')
  const [selectedPaymentMethod, setSelectedPaymentMethod] = useState<'cash' | 'mpesa' | 'card' | 'bank' | 'credit' | null>(null)
  const ordersQuery = useQuery({ queryKey: ['restaurant-orders'], queryFn: getRestaurantOrders, refetchInterval: 15000 })
  const settingsQuery = useQuery({ queryKey: ['business-settings'], queryFn: getBusinessSettings })
  const branchesQuery = useQuery({ queryKey: ['branches'], queryFn: getBranches })
  const branches = branchesQuery.data ?? []
  const branchId = selectedBranchId ?? branches.find((branch) => branch.is_default)?.id ?? branches[0]?.id
  const tablesQuery = useQuery({ queryKey: ['restaurant-tables', branchId], queryFn: () => getRestaurantTables(branchId), enabled: Boolean(branchId) })
  const registersQuery = useQuery({ queryKey: ['cash-registers'], queryFn: getCashRegisters })
  const refresh = async () => Promise.all([
    queryClient.invalidateQueries({ queryKey: ['restaurant-orders'] }),
    queryClient.invalidateQueries({ queryKey: ['inventory'] }),
    queryClient.invalidateQueries({ queryKey: ['products'] }),
    queryClient.invalidateQueries({ queryKey: ['sales'] }),
    queryClient.invalidateQueries({ queryKey: ['restaurant-tables'] }),
    queryClient.invalidateQueries({ queryKey: ['cash-registers'] }),
  ])
  const statusMutation = useMutation({
    mutationFn: ({ id, status }: { id: number; status: 'preparing' | 'ready' | 'served' | 'cancelled' }) => updateRestaurantOrderStatus(id, status),
    onSuccess: refresh,
    onError: () => setError('The order status could not be updated. Refresh and try again.'),
  })
  const paymentMutation = useMutation({
    mutationFn: (order: RestaurantOrder) => {
      const branchRegisters = (registersQuery.data ?? []).filter((item) => item.register.branch_id === order.table?.branch_id)
      const register = branchRegisters.find((item) => item.is_open) ?? branchRegisters[0]
      return createSale({
        ...(register ? { cash_register_id: register.register.id } : {}),
        restaurant_order_id: order.id,
        payment_method: paymentMethod,
        items: order.items.map((item) => ({ product_id: item.product_id, quantity: item.quantity })),
      })
    },
    onSuccess: async () => {
      setError('')
      await refresh()
    },
    onError: () => setError('Payment failed. Check that the cash register is open and the order is still active.'),
  })
  const createTableMutation = useMutation({
    mutationFn: () => createRestaurantTable({
      branch_id: branchId!,
      name: tableName.trim(),
      ...(tableArea.trim() ? { area: tableArea.trim() } : {}),
      seating_capacity: Number(tableCapacity),
    }),
    onSuccess: async () => {
      setTableName('')
      setTableArea('')
      setTableCapacity('2')
      setError('')
      await queryClient.invalidateQueries({ queryKey: ['restaurant-tables'] })
    },
    onError: () => setError('The dining table could not be created. Check its name and try again.'),
  })
  const tableStatusMutation = useMutation({
    mutationFn: ({ id, status }: { id: number; status: 'available' | 'reserved' | 'cleaning' }) => updateRestaurantTableStatus(id, status),
    onSuccess: async () => {
      setError('')
      await queryClient.invalidateQueries({ queryKey: ['restaurant-tables'] })
    },
    onError: () => setError('The table status could not be updated.'),
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
      <Card className="mb-6 p-5">
        <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
          <div><p className="eyebrow">Floor management</p><h2 className="text-lg font-semibold">Dining tables</h2></div>
          {branches.length > 1 && <label className="text-sm">Branch <select value={branchId ?? ''} onChange={(event) => setSelectedBranchId(Number(event.target.value))} className="ml-2 rounded-xl border border-graphite/10 bg-white px-3 py-2">{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></label>}
        </div>
        {branchId && <form className="mb-4 grid gap-2 md:grid-cols-[1.2fr_1fr_120px_auto]" onSubmit={(event) => { event.preventDefault(); createTableMutation.mutate() }}>
          <input value={tableName} onChange={(event) => setTableName(event.target.value)} required maxLength={80} placeholder="Table name" aria-label="Table name" className="rounded-xl border border-graphite/10 px-3 py-2 text-sm" />
          <input value={tableArea} onChange={(event) => setTableArea(event.target.value)} maxLength={80} placeholder="Area (optional)" aria-label="Table area" className="rounded-xl border border-graphite/10 px-3 py-2 text-sm" />
          <input value={tableCapacity} onChange={(event) => setTableCapacity(event.target.value)} type="number" min={1} max={1000} required aria-label="Seating capacity" className="rounded-xl border border-graphite/10 px-3 py-2 text-sm" />
          <Button type="submit" disabled={createTableMutation.isPending || !tableName.trim()}>{createTableMutation.isPending ? 'Adding...' : 'Add table'}</Button>
        </form>}
        {tablesQuery.isError && <p role="alert" className="text-sm text-red-700">Dining tables could not be loaded.</p>}
        {tablesQuery.isPending ? <p className="text-sm text-graphite/60">Loading dining tables...</p> : (tablesQuery.data ?? []).length === 0 ? <p className="text-sm text-graphite/60">No managed tables yet. Add tables here, then select them when sending dine-in orders.</p> : (
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">{(tablesQuery.data ?? []).map((table) => <article key={table.id} className="rounded-xl border border-graphite/10 p-3">
            <div className="flex items-start justify-between gap-2"><div><strong>{table.name}</strong><p className="text-xs text-graphite/60">{table.area ?? 'Dining area'} · {table.seating_capacity} seats</p></div><span className="rounded-full bg-champagne px-2 py-1 text-xs capitalize">{table.status}</span></div>
            <div className="mt-3 flex gap-2">{table.status === 'cleaning' && <Button variant="secondary" disabled={tableStatusMutation.isPending} onClick={() => tableStatusMutation.mutate({ id: table.id, status: 'available' })}>Mark available</Button>}{table.status === 'available' && <Button variant="secondary" disabled={tableStatusMutation.isPending} onClick={() => tableStatusMutation.mutate({ id: table.id, status: 'reserved' })}>Reserve</Button>}{table.status === 'reserved' && <Button variant="secondary" disabled={tableStatusMutation.isPending} onClick={() => tableStatusMutation.mutate({ id: table.id, status: 'available' })}>Release</Button>}</div>
          </article>)}</div>
        )}
      </Card>
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
