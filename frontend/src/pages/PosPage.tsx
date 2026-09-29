import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Minus, Plus, Printer, Search, Trash2 } from 'lucide-react'
import { useDeferredValue, useState } from 'react'
import { createSale, getProducts, type Product, type Sale } from '../api/commerce'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'

export default function PosPage() {
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [cart, setCart] = useState<Record<number, number>>({})
  const [selectedProducts, setSelectedProducts] = useState<Record<number, Product>>({})
  const [paymentMethod, setPaymentMethod] = useState<'cash' | 'credit'>('cash')
  const [receipt, setReceipt] = useState<Sale | null>(null)
  const deferredSearch = useDeferredValue(search)
  const productsQuery = useQuery({
    queryKey: ['pos-products', deferredSearch],
    queryFn: () => getProducts(deferredSearch, true),
  })
  const checkoutMutation = useMutation({
    mutationFn: createSale,
    onSuccess: async (sale) => {
      setReceipt(sale)
      setCart({})
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['pos-products'] }),
        queryClient.invalidateQueries({ queryKey: ['products'] }),
        queryClient.invalidateQueries({ queryKey: ['inventory'] }),
        queryClient.invalidateQueries({ queryKey: ['sales'] }),
      ])
    },
  })

  const products = productsQuery.data ?? []
  const productsById = new Map(products.map((product) => [product.id, product]))
  const cartLines = Object.entries(cart).flatMap(([productId, quantity]) => {
    const product = productsById.get(Number(productId)) ?? selectedProducts[Number(productId)]
    return product ? [{ product, quantity }] : []
  })
  const subtotalCents = cartLines.reduce((total, line) => total + toCents(line.product.unit_price) * line.quantity, 0)

  function addProduct(product: Product) {
    setReceipt(null)
    setSelectedProducts((current) => ({ ...current, [product.id]: product }))
    setCart((current) => ({
      ...current,
      [product.id]: Math.min((current[product.id] ?? 0) + 1, product.quantity_on_hand),
    }))
  }

  function changeQuantity(product: Product, change: number) {
    setCart((current) => {
      const nextQuantity = (current[product.id] ?? 0) + change
      if (nextQuantity <= 0) {
        const nextCart = { ...current }
        delete nextCart[product.id]
        return nextCart
      }

      return { ...current, [product.id]: Math.min(nextQuantity, product.quantity_on_hand) }
    })
  }

  function checkout() {
    checkoutMutation.mutate({
      payment_method: paymentMethod,
      items: cartLines.map(({ product, quantity }) => ({ product_id: product.id, quantity })),
    })
  }

  return (
    <section className="module-page">
      <div className="mb-6 flex items-center justify-between gap-4">
        <div>
          <p className="eyebrow">Checkout</p>
          <h1>Point of Sale</h1>
        </div>
        <Button variant="primary">Complete sale</Button>
      </div>

      <div className="grid gap-6 xl:grid-cols-[1.15fr_0.85fr]">
        <Card className="p-4">
          <label className="mb-4 flex items-center gap-3 rounded-xl border border-graphite/10 px-4 py-3">
            <Search className="h-4 w-4 text-graphite/50" />
            <input value={search} onChange={(event) => setSearch(event.target.value)} className="w-full bg-transparent text-sm outline-none" placeholder="Search products or scan barcode" />
          </label>
          {productsQuery.isError && <p role="alert" className="mb-4 text-sm text-danger">Products could not be loaded. Check the API connection and business context.</p>}
          {productsQuery.isPending ? (
            <p className="p-6 text-center text-sm text-graphite/60">Loading products...</p>
          ) : products.length === 0 ? (
            <p className="p-6 text-center text-sm text-graphite/60">No active products with this search.</p>
          ) : (
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
              {products.map((product) => (
                <button key={product.id} type="button" disabled={product.quantity_on_hand === 0} onClick={() => addProduct(product)} className="rounded-xl border border-graphite/10 bg-white p-4 text-left transition hover:border-lime hover:bg-lime/5 disabled:cursor-not-allowed disabled:opacity-50">
                  <p className="text-base font-bold text-graphite">{product.name}</p>
                  <p className="mt-1 text-xs text-graphite/55">{product.sku}</p>
                  <div className="mt-4 flex items-center justify-between gap-2">
                    <span className="text-sm font-semibold text-graphite">KES {product.unit_price}</span>
                    <span className="text-xs text-graphite/60">{product.quantity_on_hand} in stock</span>
                  </div>
                </button>
              ))}
            </div>
          )}
        </Card>

        <Card className="p-5">
          <div className="mb-5 flex items-center justify-between">
            <h2 className="text-xl font-bold text-graphite">Cart</h2>
            <span className="rounded-full bg-lime/20 px-2 py-1 text-xs font-bold text-graphite">{cartLines.reduce((count, line) => count + line.quantity, 0)} items</span>
          </div>

          <div className="space-y-3">
            {cartLines.map(({ product, quantity }) => (
              <div key={product.id} className="flex items-center justify-between gap-3 rounded-xl border border-graphite/10 p-3">
                <div>
                  <p className="font-medium text-graphite">{product.name}</p>
                  <p className="text-xs text-graphite/60">KES {product.unit_price} each</p>
                </div>
                <div className="flex items-center gap-2">
                  <button type="button" title="Decrease quantity" aria-label={`Decrease ${product.name}`} onClick={() => changeQuantity(product, -1)} className="grid h-8 w-8 place-items-center rounded-lg border border-graphite/10"><Minus size={14} /></button>
                  <span className="min-w-5 text-center text-sm font-semibold">{quantity}</span>
                  <button type="button" title="Increase quantity" aria-label={`Increase ${product.name}`} disabled={quantity >= product.quantity_on_hand} onClick={() => changeQuantity(product, 1)} className="grid h-8 w-8 place-items-center rounded-lg border border-graphite/10 disabled:opacity-40"><Plus size={14} /></button>
                  <button type="button" title="Remove from cart" aria-label={`Remove ${product.name}`} onClick={() => changeQuantity(product, -quantity)} className="grid h-8 w-8 place-items-center rounded-lg text-danger"><Trash2 size={14} /></button>
                </div>
              </div>
            ))}
            {cartLines.length === 0 && <p className="rounded-xl border border-dashed border-graphite/15 p-6 text-center text-sm text-graphite/55">Add products to begin a sale.</p>}
          </div>

          <div className="mt-6 space-y-3 border-t border-graphite/10 pt-4 text-sm text-graphite/70">
            <div className="flex justify-between text-base font-black text-graphite"><span>Subtotal</span><span>{formatKes(subtotalCents)}</span></div>
            <label className="grid gap-1 text-xs font-medium text-graphite/70">Payment method
              <select value={paymentMethod} onChange={(event) => setPaymentMethod(event.target.value as 'cash' | 'credit')} className="rounded-xl border border-graphite/10 bg-white px-3 py-2.5 text-sm text-graphite">
                <option value="cash">Cash</option>
                <option value="credit">Store credit</option>
              </select>
            </label>
          </div>

          {checkoutMutation.isError && <p role="alert" className="mt-4 text-sm text-danger">Checkout failed. Stock may have changed; refresh products and try again.</p>}
          <Button className="mt-5 w-full" disabled={!cartLines.length || checkoutMutation.isPending} onClick={checkout}>
            {checkoutMutation.isPending ? 'Processing...' : paymentMethod === 'credit' ? 'Record credit sale' : 'Complete cash sale'}
          </Button>

          {receipt && (
            <div className="mt-5 rounded-xl border border-lime/50 bg-lime/10 p-4">
              <p className="text-sm font-bold text-graphite">Sale recorded</p>
              <p className="mt-1 text-xs text-graphite/70">{receipt.receipt_number} · {receipt.status}</p>
              <p className="mt-2 text-lg font-black text-graphite">KES {receipt.total}</p>
              <button type="button" onClick={() => window.print()} className="mt-3 inline-flex items-center gap-2 text-sm font-semibold text-graphite"><Printer size={15} /> Print receipt</button>
            </div>
          )}
        </Card>
      </div>
    </section>
  )
}

function toCents(amount: string): number {
  const [units, fraction = ''] = amount.split('.')
  return Number(units) * 100 + Number(fraction.padEnd(2, '0').slice(0, 2))
}

function formatKes(amountInCents: number): string {
  return new Intl.NumberFormat('en-KE', { style: 'currency', currency: 'KES' }).format(amountInCents / 100)
}
