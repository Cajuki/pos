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
  RefreshCw,
  Search,
  Smartphone,
  Tag,
  Trash2,
  WalletCards,
  X,
  PlusCircle,
} from 'lucide-react'
import { useDeferredValue, useState, type FormEvent, type KeyboardEvent } from 'react'
import {
  closeCashRegister,
  createCashRegister,
  createSale,
  getCustomers,
  getCashRegisterStatus,
  getCashRegisters,
  getCategories,
  getBusinessSettings,
  getProductByBarcode,
  getProducts,
  getBusinessProviders,
  openCashRegister,
  createRestaurantOrder,
  type Product,
  type Sale,
} from '../api/commerce'
import { formatCurrency } from '../lib/utils'

const paymentOptions = [
  { value: 'cash', label: 'Cash', icon: Banknote },
  { value: 'mpesa', label: 'M-Pesa', icon: Smartphone },
  { value: 'card', label: 'Card', icon: CreditCard },
  { value: 'bank', label: 'Bank', icon: WalletCards },
  { value: 'credit', label: 'Credit', icon: Clock3 },
] as const

export default function PosPage({ canManageRegisters }: { canManageRegisters: boolean }) {
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [scanMessage, setScanMessage] = useState('')
  const [customerSearch, setCustomerSearch] = useState('')
  const [selectedCustomer, setSelectedCustomer] = useState<{ id: number; name: string; phone: string | null } | null>(null)
  const [discountPercent, setDiscountPercent] = useState('0')
  const [selectedCategory, setSelectedCategory] = useState('All items')
  const [cart, setCart] = useState<Record<number, number>>({})
  const [cartBarcodes, setCartBarcodes] = useState<Record<number, string[]>>({})
  const [serviceProviders, setServiceProviders] = useState<Record<number, number>>({})
  const [orderType, setOrderType] = useState<'standard' | 'dine_in' | 'takeaway' | 'delivery'>('standard')
  const [tableName, setTableName] = useState('')
  const [selectedProducts, setSelectedProducts] = useState<Record<number, Product>>({})
  const [selectedPaymentMethod, setSelectedPaymentMethod] = useState<typeof paymentOptions[number]['value'] | null>(null)
  const [receipt, setReceipt] = useState<Sale | null>(null)
  const [cashReceived, setCashReceived] = useState('')
  const [openingBalance, setOpeningBalance] = useState('')
  const [actualCash, setActualCash] = useState('')
  const [showCloseForm, setShowCloseForm] = useState(false)
  const [showCreateRegister, setShowCreateRegister] = useState(false)
  const [newRegisterName, setNewRegisterName] = useState('')
  const [newRegisterCode, setNewRegisterCode] = useState('')
  const [selectedRegisterId, setSelectedRegisterId] = useState<number | undefined>(() => {
    const businessId = localStorage.getItem('pos_business_id') ?? 'default'
    const storedId = localStorage.getItem(`pos_register_${businessId}`)
    return storedId ? Number(storedId) : undefined
  })
  const deferredSearch = useDeferredValue(search)
  const productsQuery = useQuery({
    queryKey: ['pos-products', deferredSearch],
    queryFn: () => getProducts(deferredSearch, true),
  })
  const settingsQuery = useQuery({ queryKey: ['business-settings'], queryFn: getBusinessSettings })
  const providersQuery = useQuery({ queryKey: ['business-providers'], queryFn: getBusinessProviders })
  const customerQuery = useQuery({
    queryKey: ['customers', 'pos', customerSearch],
    queryFn: () => getCustomers(customerSearch),
    enabled: customerSearch.trim().length >= 2,
  })
  const categoriesQuery = useQuery({ queryKey: ['categories'], queryFn: getCategories })
  const registersQuery = useQuery({ queryKey: ['cash-registers'], queryFn: getCashRegisters, retry: 1 })
  const registerQuery = useQuery({
    queryKey: ['cash-register', selectedRegisterId],
    queryFn: () => getCashRegisterStatus(selectedRegisterId),
    retry: 1,
  })
  const checkoutMutation = useMutation({
    mutationFn: createSale,
    onSuccess: async (sale) => {
      setReceipt(sale)
      setCart({})
      setCartBarcodes({})
      setServiceProviders({})
      setCashReceived('')
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['pos-products'] }),
        queryClient.invalidateQueries({ queryKey: ['products'] }),
        queryClient.invalidateQueries({ queryKey: ['inventory'] }),
        queryClient.invalidateQueries({ queryKey: ['sales'] }),
      ])
    },
  })
  const restaurantOrderMutation = useMutation({
    mutationFn: createRestaurantOrder,
    onSuccess: async () => {
      setCart({})
      setCartBarcodes({})
      setServiceProviders({})
      setTableName('')
      setScanMessage('Order sent to the kitchen.')
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['restaurant-orders'] }),
        queryClient.invalidateQueries({ queryKey: ['inventory'] }),
        queryClient.invalidateQueries({ queryKey: ['products'] }),
        queryClient.invalidateQueries({ queryKey: ['pos-products'] }),
      ])
    },
  })
  const openRegisterMutation = useMutation({
    mutationFn: openCashRegister,
    onSuccess: async () => {
      setOpeningBalance('')
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['cash-register'] }),
        queryClient.invalidateQueries({ queryKey: ['cash-registers'] }),
      ])
    },
  })
  const closeRegisterMutation = useMutation({
    mutationFn: closeCashRegister,
    onSuccess: async () => {
      setShowCloseForm(false)
      setActualCash('')
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['cash-register'] }),
        queryClient.invalidateQueries({ queryKey: ['cash-registers'] }),
      ])
    },
  })
  const createRegisterMutation = useMutation({
    mutationFn: createCashRegister,
    onSuccess: async (registerState) => {
      const registerId = registerState.register.id
      const businessId = localStorage.getItem('pos_business_id') ?? 'default'
      localStorage.setItem(`pos_register_${businessId}`, String(registerId))
      setSelectedRegisterId(registerId)
      setNewRegisterName('')
      setNewRegisterCode('')
      setShowCreateRegister(false)
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['cash-register'] }),
        queryClient.invalidateQueries({ queryKey: ['cash-registers'] }),
      ])
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
  const registerId = selectedRegisterId ?? registerQuery.data?.register.id
  const businessSettings = settingsQuery.data
  const preferences = businessSettings?.settings
  const taxRate = preferences?.tax_enabled ? Number(preferences.tax_rate) : 0
  const discountRate = Number(discountPercent) || 0
  const discountCents = Math.round(subtotalCents * Math.min(discountRate, 100) / 100)
  const discountedTaxCents = taxRate === 0
    ? 0
    : preferences?.tax_inclusive
      ? Math.round((subtotalCents - discountCents) * taxRate / (100 + taxRate))
      : Math.round((subtotalCents - discountCents) * taxRate / 100)
  const discountedTotalCents = (preferences?.tax_inclusive ? subtotalCents - discountCents : subtotalCents - discountCents + discountedTaxCents)
  const cashReceivedIsValid = /^\d+(?:\.\d{0,2})?$/.test(cashReceived.trim())
  const cashReceivedCents = cashReceived.trim() && cashReceivedIsValid
    ? toCents(cashReceived.trim())
    : discountedTotalCents
  const currency = businessSettings?.currency ?? 'KES'
  const enabledPaymentMethods = preferences?.payment_methods ?? []
  const defaultPaymentMethod = preferences?.default_payment_method
  const fallbackPaymentMethod = defaultPaymentMethod && enabledPaymentMethods.includes(defaultPaymentMethod)
    ? defaultPaymentMethod
    : enabledPaymentMethods[0] ?? 'cash'
  const paymentMethod = selectedPaymentMethod && enabledPaymentMethods.includes(selectedPaymentMethod)
    ? selectedPaymentMethod
    : fallbackPaymentMethod
  const changeGivenCents = Math.max(0, cashReceivedCents - discountedTotalCents)
  const cashPaymentIsValid = paymentMethod !== 'cash'
    || (!cashReceived.trim() || (cashReceivedIsValid && cashReceivedCents >= discountedTotalCents))

  function addProduct(product: Product, trackedBarcode?: string) {
    setReceipt(null)
    setSelectedProducts((current) => ({ ...current, [product.id]: product }))
    setCart((current) => ({
      ...current,
      [product.id]: product.product_type === 'service' || product.barcode_tracking_enabled
        ? (current[product.id] ?? 0) + 1
        : Math.min((current[product.id] ?? 0) + 1, product.quantity_available),
    }))
    if (trackedBarcode) {
      setCartBarcodes((current) => ({ ...current, [product.id]: [...(current[product.id] ?? []), trackedBarcode] }))
    }
  }

  async function handleSearchKeyDown(event: KeyboardEvent<HTMLInputElement>) {
    if (event.key !== 'Enter' || !search.trim()) {
      return
    }

    event.preventDefault()
    const scannedBarcode = search.trim()

    try {
      const lookup = await getProductByBarcode(scannedBarcode)

      if (!lookup) {
        setScanMessage(`No active product found for barcode ${scannedBarcode}.`)
        return
      }
      if (lookup.status === 'sold') {
        setScanMessage(`Barcode ${scannedBarcode} has already been sold.`)
        return
      }
      const product = lookup.product

      if (lookup.tracked_barcode && (cartBarcodes[product.id] ?? []).includes(lookup.tracked_barcode)) {
        setScanMessage(`Barcode ${scannedBarcode} is already in this basket.`)
        return
      }

      if (product.product_type === 'goods' && product.quantity_available === 0) {
        setScanMessage(`${product.name} is out of stock.`)
        return
      }

      if (product.product_type === 'goods' && (cart[product.id] ?? 0) >= product.quantity_available) {
        setScanMessage(`No more ${product.name} is available in stock.`)
        return
      }

      if (product.barcode_tracking_enabled && !lookup.tracked_barcode) {
        setScanMessage(`${product.name} requires scanning an individually tracked unit barcode.`)
        return
      }
      addProduct(product, lookup.tracked_barcode ?? undefined)
      setSearch('')
      setScanMessage(`${product.name} added to the basket.`)
    } catch {
      setScanMessage('The barcode could not be checked. Verify the API connection and try again.')
    }
  }

  function changeQuantity(product: Product, change: number) {
    setReceipt(null)
    if (change < 0 && product.barcode_tracking_enabled) {
      const nextQuantity = (cart[product.id] ?? 0) + change
      setCartBarcodes((barcodes) => {
        const nextBarcodes = { ...barcodes }
        if (nextQuantity <= 0) {
          delete nextBarcodes[product.id]
        } else {
          nextBarcodes[product.id] = (barcodes[product.id] ?? []).slice(0, nextQuantity)
        }
        return nextBarcodes
      })
    }
    setCart((current) => {
      const nextQuantity = (current[product.id] ?? 0) + change
      if (nextQuantity <= 0) {
        const nextCart = { ...current }
        delete nextCart[product.id]
        return nextCart
      }

      return {
        ...current,
        [product.id]: product.product_type === 'service'
          ? nextQuantity
          : Math.min(nextQuantity, product.quantity_available),
      }
    })
  }

  function checkout() {
    checkoutMutation.mutate({
      ...(registerId ? { cash_register_id: registerId } : {}),
      payment_method: paymentMethod,
      ...(paymentMethod === 'cash' ? { cash_received: (cashReceivedCents / 100).toFixed(2) } : {}),
      customer_id: selectedCustomer?.id,
      discount_percent: discountRate,
      ...(orderType !== 'standard' ? { order_type: orderType } : {}),
      ...(orderType === 'dine_in' ? { table_name: tableName.trim() } : {}),
      items: cartLines.map(({ product, quantity }) => ({
        product_id: product.id,
        quantity,
        ...(product.barcode_tracking_enabled ? { barcodes: cartBarcodes[product.id] ?? [] } : {}),
        ...(serviceProviders[product.id] ? { performed_by_user_id: serviceProviders[product.id] } : {}),
      })),
    })
  }

  function sendToKitchen() {
    if (orderType === 'standard') return
    restaurantOrderMutation.mutate({
      customer_id: selectedCustomer?.id,
      order_type: orderType,
      ...(orderType === 'dine_in' ? { table_name: tableName.trim() } : {}),
      items: cartLines.map(({ product, quantity }) => ({ product_id: product.id, quantity })),
    })
  }

  const canSendToKitchen = orderType !== 'standard'
    && cartLines.length > 0
    && (orderType !== 'dine_in' || Boolean(tableName.trim()))
    && !cartLines.some(({ product }) => product.barcode_tracking_enabled)

  return (
    <section className="pos-workspace">
      <header className="pos-heading">
        <div>
          <p className="pos-kicker">SELLFLUX · POINT OF SALE</p>
          <h1>New sale</h1>
          <p className="pos-subtitle">Find products, build an order, and check out in one place.</p>
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
                <button key={product.id} type="button" disabled={(product.product_type === 'goods' && product.quantity_available === 0) || product.barcode_tracking_enabled} onClick={() => addProduct(product)} className="pos-product-card" title={product.barcode_tracking_enabled ? 'Scan an individual unit barcode to add this product' : undefined}>
                  <span className="pos-product-mark" aria-hidden="true">{product.name.slice(0, 1).toUpperCase()}</span>
                  <span className="pos-product-copy"><strong>{product.name}</strong><small>{product.category || product.sku}</small></span>
                  <span className="pos-product-bottom"><strong>{formatCurrency(Number(product.unit_price), currency)}</strong><small className={product.product_type === 'goods' && product.quantity_available <= product.reorder_level ? 'low-stock' : ''}>{product.product_type === 'service' ? `${product.service_duration_minutes ?? 30} min service` : `${product.quantity_available} available`}</small></span>
                  <span className="pos-add-mark"><Plus size={16} /></span>
                </button>
              ))}
            </div>
          )}
        </div>

        <aside className="pos-checkout">
          <div className={`pos-register-card ${registerOpen ? 'is-register-open' : ''}`}>
            <div className="pos-register-heading"><span className="pos-register-icon"><Clock3 size={17} /></span><div><strong>{registerOpen ? 'Active shift' : 'Start your shift'}</strong><small>{registerQuery.data?.register.name ?? 'Main counter'}</small></div>{registerOpen && <span className="pos-open-label">OPEN</span>}</div>
            {registersQuery.data && registersQuery.data.length > 1 && (
              <label className="pos-register-picker">Till
                <select
                  value={registerId ?? ''}
                  disabled={cartLines.length > 0}
                  onChange={(event) => {
                    const nextId = Number(event.target.value)
                    const businessId = localStorage.getItem('pos_business_id') ?? 'default'
                    localStorage.setItem(`pos_register_${businessId}`, String(nextId))
                    setSelectedRegisterId(nextId)
                    setShowCloseForm(false)
                  }}
                >
                  {registersQuery.data.map(({ register }) => <option value={register.id} key={register.id}>{register.name} ({register.code})</option>)}
                </select>
                {cartLines.length > 0 && <small>Finish or clear the sale before switching tills.</small>}
              </label>
            )}
            {canManageRegisters && (
              <button className="pos-add-register-toggle" type="button" onClick={() => setShowCreateRegister((shown) => !shown)}>
                <PlusCircle size={14} /> {showCreateRegister ? 'Cancel adding till' : 'Add a till'}
              </button>
            )}
            {showCreateRegister && canManageRegisters && (
              <form className="pos-create-register-form" onSubmit={(event: FormEvent<HTMLFormElement>) => {
                event.preventDefault()
                createRegisterMutation.mutate({ name: newRegisterName, code: newRegisterCode })
              }}>
                <label>Till name<input required maxLength={100} value={newRegisterName} onChange={(event) => setNewRegisterName(event.target.value)} placeholder="Front counter" /></label>
                <label>Till code<input required maxLength={40} pattern="[A-Za-z0-9-]+" value={newRegisterCode} onChange={(event) => setNewRegisterCode(event.target.value.toUpperCase())} placeholder="TILL-02" /></label>
                {createRegisterMutation.isError && <p role="alert">Could not add this till. Check that its code is unique.</p>}
                <button type="submit" disabled={createRegisterMutation.isPending}>{createRegisterMutation.isPending ? 'Adding till…' : 'Create till'}</button>
              </form>
            )}
            {registerQuery.isPending ? <p className="pos-register-message">Checking register status...</p> : registerQuery.isError ? (
              <div className="pos-register-message pos-register-error">
                <span>Register status could not be checked. Retry before taking payment.</span>
                <button type="button" onClick={() => void registerQuery.refetch()} disabled={registerQuery.isFetching}>
                  <RefreshCw size={13} /> {registerQuery.isFetching ? 'Checking...' : 'Retry'}
                </button>
              </div>
            ) : registerOpen ? (
              showCloseForm ? (
                <form className="pos-register-form" onSubmit={(event) => { event.preventDefault(); closeRegisterMutation.mutate({ actual_cash: Number(actualCash), ...(registerId ? { register_id: registerId } : {}) }) }}>
                  <label>Counted cash<input type="number" min="0" step="0.01" required value={actualCash} onChange={(event) => setActualCash(event.target.value)} placeholder="0.00" /></label>
                  {closeRegisterMutation.isError && <p role="alert">Could not close this shift. Check the counted amount and retry.</p>}
                  <div><button type="button" className="pos-cancel-button" onClick={() => setShowCloseForm(false)}>Cancel</button><button type="submit" disabled={closeRegisterMutation.isPending || cartLines.length > 0}>{closeRegisterMutation.isPending ? 'Closing...' : 'Close shift'}</button></div>
                  {cartLines.length > 0 && <small>Complete or clear the basket before closing.</small>}
                </form>
              ) : (
                <div className="pos-register-active"><span>Float {formatCurrency(Number(registerQuery.data?.current_session?.opening_balance ?? '0'), currency)}</span><button type="button" onClick={() => setShowCloseForm(true)} disabled={cartLines.length > 0}>Close shift <ChevronRight size={14} /></button></div>
              )
            ) : (
              <form className="pos-register-form" onSubmit={(event) => { event.preventDefault(); openRegisterMutation.mutate({ opening_balance: Number(openingBalance || 0), ...(registerId ? { register_id: registerId } : {}) }) }}>
                <label>Opening float<input type="number" min="0" step="0.01" required value={openingBalance} onChange={(event) => setOpeningBalance(event.target.value)} placeholder="0.00" /></label>
                {openRegisterMutation.isError && <p role="alert">Could not open the register. Please retry.</p>}
                <button type="submit" disabled={openRegisterMutation.isPending}>{openRegisterMutation.isPending ? 'Opening...' : 'Open register'} <ChevronRight size={14} /></button>
              </form>
            )}
          </div>

          <div className="pos-cart-heading">
            <div><p className="pos-kicker">CURRENT ORDER</p><h2>Sale details <span>{itemCount}</span></h2></div>
            {cartLines.length > 0 && <button className="pos-clear-button" type="button" onClick={() => { setCart({}); setCartBarcodes({}); setReceipt(null) }}>Clear all</button>}
          </div>

          <div className="pos-cart-lines">
            {cartLines.map(({ product, quantity }) => (
              <div key={product.id} className="pos-cart-line">
                <div className="pos-line-info"><strong>{product.name}</strong><small>{formatCurrency(Number(product.unit_price), currency)} each</small></div>
                {product.product_type === 'service' && (
                  <label className="pos-provider-picker">
                    <span>Staff member</span>
                    <select
                      aria-label={`Staff member for ${product.name}`}
                      value={serviceProviders[product.id] ?? ''}
                      onChange={(event) => setServiceProviders((current) => ({
                        ...current,
                        [product.id]: Number(event.target.value),
                      }))}
                    >
                      <option value="">Unassigned</option>
                      {(providersQuery.data ?? []).map((provider) => (
                        <option key={provider.id} value={provider.id}>{provider.name}</option>
                      ))}
                    </select>
                  </label>
                )}
                <div className="pos-quantity-control">
                  <button type="button" title="Decrease quantity" aria-label={`Decrease ${product.name}`} onClick={() => changeQuantity(product, -1)}><Minus size={13} /></button>
                  <span>{quantity}</span>
                  <button type="button" title="Increase quantity" aria-label={`Increase ${product.name}`} disabled={product.barcode_tracking_enabled || (product.product_type === 'goods' && quantity >= product.quantity_available)} onClick={() => changeQuantity(product, 1)}><Plus size={13} /></button>
                </div>
                <strong className="pos-line-total">{formatCurrency(Number(product.unit_price) * quantity, currency)}</strong>
                <button className="pos-remove-line" type="button" title="Remove item" aria-label={`Remove ${product.name}`} onClick={() => changeQuantity(product, -quantity)}><Trash2 size={15} /></button>
              </div>
            ))}
            {cartLines.length === 0 && <div className="pos-cart-empty"><span><ShoppingBasketIcon /></span><strong>Your basket is ready</strong><small>Select an item to add it to this sale.</small></div>}
          </div>

          <div className="pos-payment-area">
            <div className="pos-order-type-picker">
              <label htmlFor="pos-order-type">Order type</label>
              <select id="pos-order-type" value={orderType} onChange={(event) => setOrderType(event.target.value as typeof orderType)}>
                <option value="standard">Standard sale</option>
                <option value="dine_in">Dine in</option>
                <option value="takeaway">Takeaway</option>
                <option value="delivery">Delivery</option>
              </select>
              {orderType === 'dine_in' && (
                <input
                  aria-label="Table name or number"
                  value={tableName}
                  onChange={(event) => setTableName(event.target.value)}
                  placeholder="Table name or number"
                />
              )}
              <button
                className="pos-kitchen-button"
                type="button"
                disabled={!canSendToKitchen || restaurantOrderMutation.isPending}
                onClick={sendToKitchen}
              >
                {restaurantOrderMutation.isPending ? 'Sending...' : 'Send to kitchen'}
              </button>
              {restaurantOrderMutation.isError && <p role="alert">Order could not be sent. Check stock and retry.</p>}
              {cartLines.some(({ product }) => product.barcode_tracking_enabled) && (
                <small>Individually barcoded items must be scanned and paid at checkout; send other items as kitchen orders.</small>
              )}
            </div>
            <div className="pos-customer-picker">
              <label htmlFor="pos-customer-search">Customer <small>(optional)</small></label>
              {selectedCustomer ? (
                <div className="pos-selected-customer">
                  <span><strong>{selectedCustomer.name}</strong>{selectedCustomer.phone && <small>{selectedCustomer.phone}</small>}</span>
                  <button type="button" onClick={() => { setSelectedCustomer(null); setCustomerSearch('') }} aria-label="Remove selected customer"><X size={15} /></button>
                </div>
              ) : (
                <>
                  <input id="pos-customer-search" value={customerSearch} onChange={(event) => setCustomerSearch(event.target.value)} placeholder="Search by name or phone" />
                  {customerSearch.trim().length >= 2 && (
                    <div className="pos-customer-results">
                      {customerQuery.isPending && <span>Searching customers...</span>}
                      {customerQuery.isError && <span role="alert">Customers could not be searched.</span>}
                      {(customerQuery.data ?? []).filter((customer) => customer.status === 'active').map((customer) => (
                        <button key={customer.id} type="button" onClick={() => { setSelectedCustomer({ id: customer.id, name: customer.name, phone: customer.phone }); setCustomerSearch('') }}>
                          <strong>{customer.name}</strong><small>{customer.phone ?? customer.email ?? 'No contact details'}</small>
                        </button>
                      ))}
                      {!customerQuery.isPending && !customerQuery.isError && !customerQuery.data?.some((customer) => customer.status === 'active') && <span>No matching active customers found.</span>}
                    </div>
                  )}
                </>
              )}
            </div>
            {preferences?.discount_enabled && (
              <label className="pos-discount-control">Discount (%)
                <input type="number" min="0" max={preferences.max_discount_percent} step="0.01" value={discountPercent} onChange={(event) => setDiscountPercent(event.target.value)} aria-label="Discount percentage" />
                <small>Up to {preferences.max_discount_percent}%</small>
              </label>
            )}
            <div className="pos-total-row"><span>Subtotal <small>({itemCount} items)</small></span><strong>{formatCurrency(subtotalCents / 100, currency)}</strong></div>
            {discountCents > 0 && <div className="pos-total-row"><span>Discount ({discountRate}%)</span><strong>−{formatCurrency(discountCents / 100, currency)}</strong></div>}
            {preferences?.tax_enabled && <div className="pos-total-row"><span>{preferences.tax_inclusive ? 'Tax included' : 'Tax'} ({preferences.tax_rate}%)</span><strong>{formatCurrency(discountedTaxCents / 100, currency)}</strong></div>}
            <div className="pos-total-row pos-grand-total"><span>Total due</span><strong>{formatCurrency(discountedTotalCents / 100, currency)}</strong></div>
            {paymentMethod === 'cash' && (
              <div className="pos-cash-calculator">
                <label htmlFor="pos-cash-received">Cash received</label>
                <div className="pos-cash-input-row">
                  <input
                    id="pos-cash-received"
                    type="number"
                    min={discountedTotalCents / 100}
                    step="0.01"
                    inputMode="decimal"
                    value={cashReceived}
                    onChange={(event) => setCashReceived(event.target.value)}
                    placeholder={(discountedTotalCents / 100).toFixed(2)}
                    aria-describedby="pos-cash-change"
                  />
                  <button type="button" onClick={() => setCashReceived((discountedTotalCents / 100).toFixed(2))}>Exact amount</button>
                </div>
                <div id="pos-cash-change" className={`pos-cash-change ${cashPaymentIsValid ? '' : 'is-underpaid'}`} aria-live="polite">
                  <span>{!cashReceivedIsValid && cashReceived.trim() ? 'Enter a valid amount' : cashPaymentIsValid ? 'Change due' : 'Amount still owed'}</span>
                  {cashReceivedIsValid && <strong>{formatCurrency(cashPaymentIsValid ? changeGivenCents / 100 : (discountedTotalCents - cashReceivedCents) / 100, currency)}</strong>}
                </div>
              </div>
            )}
            <fieldset className="pos-tender-options">
              <legend>Payment method</legend>
              {paymentOptions.filter(({ value }) => enabledPaymentMethods.includes(value)).map(({ value, label, icon: Icon }) => (
                <button key={value} className={paymentMethod === value ? 'selected' : ''} type="button" aria-pressed={paymentMethod === value} onClick={() => setSelectedPaymentMethod(value)}>
                  <Icon size={16} /><span>{label}</span>{paymentMethod === value && <Check size={13} />}
                </button>
              ))}
            </fieldset>
            {['mpesa', 'card', 'bank'].includes(paymentMethod) && (
              <p className="pos-manual-tender-note" role="note">
                Confirm this payment in your provider or bank account before recording it. SellFlux does not initiate or verify this payment yet.
              </p>
            )}
            {settingsQuery.isError && <div role="alert" className="pos-alert pos-checkout-alert"><CircleAlert size={16} /> Checkout settings could not be loaded. Refresh before taking payment.</div>}
            {checkoutMutation.isError && <div role="alert" className="pos-alert pos-checkout-alert"><CircleAlert size={16} /> Sale failed. Check stock, register status, and enabled payment methods, then try again.</div>}
            <button className="pos-charge-button" type="button" disabled={!cartLines.length || checkoutMutation.isPending || !registerOpen || settingsQuery.isPending || settingsQuery.isError || !enabledPaymentMethods.includes(paymentMethod) || !cashPaymentIsValid} onClick={checkout}>
              <span>{checkoutMutation.isPending ? 'Processing sale...' : paymentMethod === 'credit' ? 'Record credit sale' : paymentMethod === 'cash' ? 'Complete cash sale' : 'Record confirmed payment'}</span>
              <span>{checkoutMutation.isPending ? null : <>{formatCurrency(discountedTotalCents / 100, currency)} <ChevronRight size={17} /></>}</span>
            </button>
            {!registerOpen && !registerQuery.isPending && !registerQuery.isError && <p className="pos-gate-note">Open the register above before completing a sale.</p>}
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
                <p className="receipt-brand">{businessSettings?.name ?? 'POSS POS'}</p>
                <h3>{receipt.order_type === 'dine_in' ? 'Dine-in Receipt' : receipt.order_type === 'delivery' ? 'Delivery Receipt' : 'POS Receipt'}</h3>
                <p>{new Date(receipt.created_at).toLocaleString('en-KE', { timeZone: businessSettings?.timezone ?? 'Africa/Nairobi' })}</p>
              </header>
              {preferences?.receipt_show_business_details && (
                <div className="receipt-business-details">
                  {[preferences.business_address, preferences.business_phone, preferences.business_email, preferences.tax_number && `Tax ID: ${preferences.tax_number}`].filter(Boolean).map((detail, index) => <p key={index}>{detail}</p>)}
                </div>
              )}

              <div className="receipt-meta">
                <div><span>Receipt</span><strong>{receipt.receipt_number}</strong></div>
                <div><span>Payment</span><strong>{receipt.payment_method.toUpperCase()}</strong></div>
                {receipt.customer && <div><span>Customer</span><strong>{receipt.customer.name}</strong></div>}
              </div>

              <div className="receipt-items">
                {receipt.items.map((item) => (
                  <div key={`${receipt.id}-${item.product_id}-${item.sku}`} className="receipt-item">
                    <div className="receipt-item-main">
                      <strong>{item.product_name}</strong>
                      <small>{item.quantity} × {formatCurrency(Number(item.unit_price), currency)}{item.barcode ? ` · ${item.barcode}` : ''}</small>
                    </div>
                    <span>{formatCurrency(Number(item.line_total), currency)}</span>
                  </div>
                ))}
              </div>

              <div className="receipt-summary">
                <div><span>Subtotal</span><strong>{formatCurrency(Number(receipt.subtotal), currency)}</strong></div>
                {Number(receipt.discount_amount) > 0 && <div><span>Discount</span><strong>−{formatCurrency(Number(receipt.discount_amount), currency)}</strong></div>}
                {Number(receipt.tax_amount) > 0 && <div><span>{preferences?.tax_inclusive ? 'Tax included' : 'Tax'} ({receipt.tax_rate}%)</span><strong>{formatCurrency(Number(receipt.tax_amount), currency)}</strong></div>}
                <div><span>Total</span><strong>{formatCurrency(Number(receipt.total), currency)}</strong></div>
                {receipt.payment_method === 'cash' && receipt.cash_received !== null && receipt.change_given !== null && (
                  <>
                    <div><span>Cash received</span><strong>{formatCurrency(Number(receipt.cash_received), currency)}</strong></div>
                    <div className="receipt-change"><span>Change given</span><strong>{formatCurrency(Number(receipt.change_given), currency)}</strong></div>
                  </>
                )}
              </div>

              <footer className="receipt-footer">
                <p>{preferences?.receipt_footer}</p>
              </footer>
            </div>
          </div>}

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
