import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Banknote,
  Barcode,
  Boxes,
  Check,
  ChevronRight,
  CircleAlert,
  Clock3,
  CreditCard,
  Minus,
  Plus,
  Printer,
  Search,
  Smartphone,
  Store,
  Tag,
  Trash2,
  WalletCards,
  X,
} from 'lucide-react'
import { useDeferredValue, useState, type KeyboardEvent } from 'react'
import {
  closeCashRegister,
  createSale,
  getCashRegisterStatus,
  getCategories,
  getProductByBarcode,
  getProducts,
  openCashRegister,
  type Product,
  type Sale,
} from '../api/commerce'

export default function PosPage() {
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [scanMessage, setScanMessage] = useState('')
  const [selectedCategory, setSelectedCategory] = useState('All items')
  const [cart, setCart] = useState<Record<number, number>>({})
  const [selectedProducts, setSelectedProducts] = useState<Record<number, Product>>({})
  const [paymentMethod, setPaymentMethod] = useState<'cash' | 'mpesa' | 'card' | 'bank' | 'credit'>('cash')
  const [receipt, setReceipt] = useState<Sale | null>(null)
  const [openingBalance, setOpeningBalance] = useState('')
  const [actualCash, setActualCash] = useState('')
  const [showCloseForm, setShowCloseForm] = useState(false)
  const deferredSearch = useDeferredValue(search)
  const productsQuery = useQuery({
    queryKey: ['pos-products', deferredSearch],
    queryFn: () => getProducts(deferredSearch, true),
  })
  const categoriesQuery = useQuery({ queryKey: ['categories'], queryFn: getCategories })
  const registerQuery = useQuery({ queryKey: ['cash-register'], queryFn: getCashRegisterStatus, retry: 1 })
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
  const openRegisterMutation = useMutation({
    mutationFn: openCashRegister,
    onSuccess: async () => {
      setOpeningBalance('')
      await queryClient.invalidateQueries({ queryKey: ['cash-register'] })
    },
  })
  const closeRegisterMutation = useMutation({
    mutationFn: closeCashRegister,
    onSuccess: async () => {
      setShowCloseForm(false)
      setActualCash('')
      await queryClient.invalidateQueries({ queryKey: ['cash-register'] })
    },
  })

  const products = productsQuery.data ?? []
  const categories = categoriesQuery.data ?? []
  const visibleProducts = selectedCategory === 'All items'
    ? products
    : products.filter((product) => product.category === selectedCategory)
  const productsById = new Map(products.map((product) => [product.id, product]))
  const cartLines = Object.entries(cart).flatMap(([productId, quantity]) => {
    const product = productsById.get(Number(productId)) ?? selectedProducts[Number(productId)]
    return product ? [{ product, quantity }] : []
  })
  const subtotalCents = cartLines.reduce((total, line) => total + toCents(line.product.unit_price) * line.quantity, 0)
  const itemCount = cartLines.reduce((count, line) => count + line.quantity, 0)
  const registerOpen = registerQuery.data?.is_open ?? false

  function addProduct(product: Product) {
    setReceipt(null)
    setSelectedProducts((current) => ({ ...current, [product.id]: product }))
    setCart((current) => ({
      ...current,
      [product.id]: Math.min((current[product.id] ?? 0) + 1, product.quantity_on_hand),
    }))
  }

  async function handleSearchKeyDown(event: KeyboardEvent<HTMLInputElement>) {
    if (event.key !== 'Enter' || !search.trim()) {
      return
    }

    event.preventDefault()
    const scannedBarcode = search.trim()

    try {
      const product = await getProductByBarcode(scannedBarcode)

      if (!product) {
        setScanMessage(`No active product found for barcode ${scannedBarcode}.`)
        return
      }

      if (product.quantity_on_hand === 0) {
        setScanMessage(`${product.name} is out of stock.`)
        return
      }

      if ((cart[product.id] ?? 0) >= product.quantity_on_hand) {
        setScanMessage(`No more ${product.name} is available in stock.`)
        return
      }

      addProduct(product)
      setSearch('')
      setScanMessage(`${product.name} added to the basket.`)
    } catch {
      setScanMessage('The barcode could not be checked. Verify the API connection and try again.')
    }
  }

  function changeQuantity(product: Product, change: number) {
    setReceipt(null)
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
    <section className="pos-workspace">
      <header className="pos-heading">
        <div>
          <p className="pos-kicker">POSS · CHECKOUT</p>
          <h1>Make a sale</h1>
          <p className="pos-subtitle">Build a basket, choose a tender, and keep the line moving.</p>
        </div>
        <div className={`pos-shift-pill ${registerOpen ? 'is-open' : ''}`}>
          <span className="pos-shift-dot" />
          <span>{registerOpen ? 'Register open' : registerQuery.isPending ? 'Checking register' : 'Register closed'}</span>
          {registerOpen && <small>{registerQuery.data?.register.name}</small>}
        </div>
      </header>

      <div className="pos-layout">
        <div className="pos-catalog">
          <div className="pos-catalog-toolbar">
            <label className="pos-search">
              <Search size={18} />
              <input autoFocus value={search} onChange={(event) => { setSearch(event.target.value); setScanMessage('') }} onKeyDown={handleSearchKeyDown} placeholder="Search name, SKU, or scan barcode" aria-label="Search products or scan a barcode" />
              {search ? <button type="button" onClick={() => setSearch('')} aria-label="Clear search"><X size={16} /></button> : <span><Barcode size={17} /> Scan</span>}
            </label>
            <div className="pos-result-count">{visibleProducts.length} items</div>
          </div>
          {scanMessage && <p className="pos-inline-note" role="status">{scanMessage}</p>}

          <nav className="pos-categories" aria-label="Product categories">
            <button className={selectedCategory === 'All items' ? 'active' : ''} type="button" onClick={() => setSelectedCategory('All items')}>
              <Boxes size={16} /> All items
            </button>
            {categories.map((category) => (
              <button className={selectedCategory === category.name ? 'active' : ''} key={category.id} type="button" onClick={() => setSelectedCategory(category.name)}>
                <Tag size={15} /> {category.name}
              </button>
            ))}
          </nav>

          {productsQuery.isError && <div role="alert" className="pos-alert"><CircleAlert size={17} /> Products could not be loaded. Check the API connection and business context.</div>}
          {categoriesQuery.isError && <div role="status" className="pos-inline-note">Categories are unavailable. You can still browse all items.</div>}
          {productsQuery.isPending ? (
            <div className="pos-empty"><span className="pos-empty-icon"><Boxes size={22} /></span><strong>Loading your catalog</strong><span>Available items will appear here.</span></div>
          ) : visibleProducts.length === 0 ? (
            <div className="pos-empty"><span className="pos-empty-icon"><Search size={22} /></span><strong>{products.length ? 'No items in this category' : 'Your catalog is empty'}</strong><span>{products.length ? 'Choose another category or clear your search.' : 'Add active products with stock to start selling.'}</span></div>
          ) : (
            <div className="pos-product-grid">
              {visibleProducts.map((product) => (
                <button key={product.id} type="button" disabled={product.quantity_on_hand === 0} onClick={() => addProduct(product)} className="pos-product-card">
                  <span className="pos-product-mark"><Store size={18} /></span>
                  <span className="pos-product-copy"><strong>{product.name}</strong><small>{product.category || product.sku}</small></span>
                  <span className="pos-product-bottom"><strong>{formatKes(toCents(product.unit_price))}</strong><small className={product.quantity_on_hand <= product.reorder_level ? 'low-stock' : ''}>{product.quantity_on_hand} in stock</small></span>
                  <span className="pos-add-mark"><Plus size={16} /></span>
                </button>
              ))}
            </div>
          )}
        </div>

        <aside className="pos-checkout">
          <div className="pos-cart-heading">
            <div><p className="pos-kicker">CURRENT ORDER</p><h2>Basket <span>{itemCount}</span></h2></div>
            {cartLines.length > 0 && <button className="pos-clear-button" type="button" onClick={() => { setCart({}); setReceipt(null) }}>Clear all</button>}
          </div>

          <div className="pos-cart-lines">
            {cartLines.map(({ product, quantity }) => (
              <div key={product.id} className="pos-cart-line">
                <div className="pos-line-info"><strong>{product.name}</strong><small>{formatKes(toCents(product.unit_price))} each</small></div>
                <div className="pos-quantity-control">
                  <button type="button" title="Decrease quantity" aria-label={`Decrease ${product.name}`} onClick={() => changeQuantity(product, -1)}><Minus size={13} /></button>
                  <span>{quantity}</span>
                  <button type="button" title="Increase quantity" aria-label={`Increase ${product.name}`} disabled={quantity >= product.quantity_on_hand} onClick={() => changeQuantity(product, 1)}><Plus size={13} /></button>
                </div>
                <strong className="pos-line-total">{formatKes(toCents(product.unit_price) * quantity)}</strong>
                <button className="pos-remove-line" type="button" title="Remove item" aria-label={`Remove ${product.name}`} onClick={() => changeQuantity(product, -quantity)}><Trash2 size={15} /></button>
              </div>
            ))}
            {cartLines.length === 0 && <div className="pos-cart-empty"><span><ShoppingBasketIcon /></span><strong>Your basket is ready</strong><small>Select an item to add it to this sale.</small></div>}
          </div>

          <div className="pos-payment-area">
            <div className="pos-total-row"><span>Subtotal <small>({itemCount} items)</small></span><strong>{formatKes(subtotalCents)}</strong></div>
            <div className="pos-total-row pos-grand-total"><span>Total due</span><strong>{formatKes(subtotalCents)}</strong></div>
            <fieldset className="pos-tender-options">
              <legend>Payment method</legend>
              {[
                { value: 'cash', label: 'Cash', icon: Banknote },
                { value: 'mpesa', label: 'M-Pesa', icon: Smartphone },
                { value: 'card', label: 'Card', icon: CreditCard },
                { value: 'bank', label: 'Bank', icon: WalletCards },
                { value: 'credit', label: 'Credit', icon: Clock3 },
              ].map(({ value, label, icon: Icon }) => (
                <button key={value} className={paymentMethod === value ? 'selected' : ''} type="button" aria-pressed={paymentMethod === value} onClick={() => setPaymentMethod(value as typeof paymentMethod)}>
                  <Icon size={16} /><span>{label}</span>{paymentMethod === value && <Check size={13} />}
                </button>
              ))}
            </fieldset>
            {checkoutMutation.isError && <div role="alert" className="pos-alert pos-checkout-alert"><CircleAlert size={16} /> Sale failed. Stock may have changed; try again.</div>}
            {registerQuery.isError && <div role="alert" className="pos-alert pos-checkout-alert"><CircleAlert size={16} /> Register status could not be checked.</div>}
            <button className="pos-charge-button" type="button" disabled={!cartLines.length || checkoutMutation.isPending || !registerOpen} onClick={checkout}>
              <span>{checkoutMutation.isPending ? 'Processing sale...' : paymentMethod === 'credit' ? 'Record credit sale' : 'Charge customer'}</span>
              <span>{checkoutMutation.isPending ? null : <>{formatKes(subtotalCents)} <ChevronRight size={17} /></>}</span>
            </button>
            {!registerOpen && !registerQuery.isPending && <p className="pos-gate-note">Open the register below before completing a sale.</p>}
          </div>

          {receipt && <div className="pos-receipt pos-receipt-shell">
            <div className="pos-receipt-header">
              <div className="pos-receipt-icon"><Check size={17} /></div>
              <div>
                <strong>Sale recorded</strong>
                <small>{receipt.receipt_number} · {receipt.status}</small>
              </div>
              <button type="button" onClick={() => window.print()} aria-label="Print receipt" title="Print receipt"><Printer size={17} /></button>
            </div>

            <div className="printable-receipt" aria-label="Printable sales receipt">
              <header className="receipt-header">
                <p className="receipt-brand">POSS POS</p>
                <h3>Retail Receipt</h3>
                <p>{new Date(receipt.created_at).toLocaleString()}</p>
              </header>

              <div className="receipt-meta">
                <div><span>Receipt</span><strong>{receipt.receipt_number}</strong></div>
                <div><span>Payment</span><strong>{receipt.payment_method.toUpperCase()}</strong></div>
              </div>

              <div className="receipt-items">
                {receipt.items.map((item) => (
                  <div key={`${receipt.id}-${item.product_id}-${item.sku}`} className="receipt-item">
                    <div className="receipt-item-main">
                      <strong>{item.product_name}</strong>
                      <small>{item.quantity} × {formatKes(toCents(item.unit_price))}</small>
                    </div>
                    <span>{formatKes(toCents(item.line_total))}</span>
                  </div>
                ))}
              </div>

              <div className="receipt-summary">
                <div><span>Subtotal</span><strong>{formatKes(toCents(receipt.subtotal))}</strong></div>
                <div><span>Total</span><strong>{formatKes(toCents(receipt.total))}</strong></div>
              </div>

              <footer className="receipt-footer">
                <p>Thank you for shopping with POSS.</p>
                <p>Support: help@poss.co.ke</p>
              </footer>
            </div>
          </div>}

          <div className="pos-register-card">
            <div className="pos-register-heading"><span className="pos-register-icon"><Clock3 size={17} /></span><div><strong>{registerOpen ? 'Active shift' : 'Start your shift'}</strong><small>{registerQuery.data?.register.name ?? 'Main counter'}</small></div>{registerOpen && <span className="pos-open-label">OPEN</span>}</div>
            {registerQuery.isPending ? <p className="pos-register-message">Checking register status...</p> : registerQuery.isError ? <p className="pos-register-message">Register details are unavailable. Refresh to try again.</p> : registerOpen ? (
              showCloseForm ? (
                <form className="pos-register-form" onSubmit={(event) => { event.preventDefault(); closeRegisterMutation.mutate({ actual_cash: Number(actualCash) }) }}>
                  <label>Counted cash<input type="number" min="0" step="0.01" required value={actualCash} onChange={(event) => setActualCash(event.target.value)} placeholder="0.00" /></label>
                  {closeRegisterMutation.isError && <p role="alert">Could not close this shift. Check the counted amount and retry.</p>}
                  <div><button type="button" className="pos-cancel-button" onClick={() => setShowCloseForm(false)}>Cancel</button><button type="submit" disabled={closeRegisterMutation.isPending || cartLines.length > 0}>{closeRegisterMutation.isPending ? 'Closing...' : 'Close shift'}</button></div>
                  {cartLines.length > 0 && <small>Complete or clear the basket before closing.</small>}
                </form>
              ) : (
                <div className="pos-register-active"><span>Float {formatKes(toCents(registerQuery.data?.current_session?.opening_balance ?? '0'))}</span><button type="button" onClick={() => setShowCloseForm(true)} disabled={cartLines.length > 0}>Close shift <ChevronRight size={14} /></button></div>
              )
            ) : (
              <form className="pos-register-form" onSubmit={(event) => { event.preventDefault(); openRegisterMutation.mutate({ opening_balance: Number(openingBalance || 0) }) }}>
                <label>Opening float<input type="number" min="0" step="0.01" required value={openingBalance} onChange={(event) => setOpeningBalance(event.target.value)} placeholder="0.00" /></label>
                {openRegisterMutation.isError && <p role="alert">Could not open the register. Please retry.</p>}
                <button type="submit" disabled={openRegisterMutation.isPending}>{openRegisterMutation.isPending ? 'Opening...' : 'Open register'} <ChevronRight size={14} /></button>
              </form>
            )}
          </div>
        </aside>
      </div>
    </section>
  )
}

function ShoppingBasketIcon() {
  return <Boxes size={21} />
}

function toCents(amount: string): number {
  const [units, fraction = ''] = amount.split('.')
  return Number(units) * 100 + Number(fraction.padEnd(2, '0').slice(0, 2))
}

function formatKes(amountInCents: number): string {
  return new Intl.NumberFormat('en-KE', { style: 'currency', currency: 'KES' }).format(amountInCents / 100)
}
