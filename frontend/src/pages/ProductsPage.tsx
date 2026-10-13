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
  const [trackingMode, setTrackingMode] = useState<'shared' | 'individual'>('shared')
  const [productType, setProductType] = useState<'goods' | 'service'>('goods')
  const [batchTracking, setBatchTracking] = useState(false)
  const [formError, setFormError] = useState('')
  const deferredSearch = useDeferredValue(search)
  const settingsQuery = useQuery({ queryKey: ['business-settings'], queryFn: getBusinessSettings })
  const productsQuery = useQuery({ queryKey: ['products', deferredSearch], queryFn: () => getProducts(deferredSearch) })
  const currency = settingsQuery.data?.currency ?? 'KES'
  const isPharmacy = settingsQuery.data?.industry === 'pharmacy'
  const enabledModules = settingsQuery.data?.settings.enabled_modules ?? []
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
    const variantAttributes = String(formData.get('variant_attributes') ?? '').trim()
    const bulkPricing = String(formData.get('bulk_pricing') ?? '').trim()

    if (!sku || !Number.isFinite(unitPrice) || unitPrice < 0) {
      setFormError('Enter a valid SKU and selling price.')
      return
    }
    const variant_attributes: Record<string, string> = {}
    if (variantAttributes) {
      for (const entry of variantAttributes.split(',')) {
        const [key, value, ...extra] = entry.split('=').map((part) => part.trim())
        if (!key || !value || extra.length || variant_attributes[key]) {
          setFormError('Enter variants as unique key=value pairs, for example Size=M, Colour=Blue.')
          return
        }
        variant_attributes[key] = value
      }
    }
    const bulk_pricing: Array<{ minimum_quantity: number; unit_price: number }> = []
    if (bulkPricing) {
      for (const entry of bulkPricing.split(',')) {
        const [minimum, price, ...extra] = entry.split(':').map((part) => part.trim())
        const minimum_quantity = Number(minimum)
        const unit_price = Number(price)
        if (!minimum || !price || extra.length || !Number.isInteger(minimum_quantity) || minimum_quantity < 2 || !Number.isInteger(unit_price) || unit_price < 0) {
          setFormError('Enter bulk tiers as quantity:price pairs, for example 10:90, 50:80.')
          return
        }
        bulk_pricing.push({ minimum_quantity, unit_price })
      }
    }
    setFormError('')

    createMutation.mutate({
      sku,
      barcode: productType === 'goods' && trackingMode === 'shared' ? String(formData.get('barcode') ?? '').trim() || undefined : undefined,
      barcode_tracking_enabled: productType === 'goods' && trackingMode === 'individual',
      batch_tracking_enabled: productType === 'goods' && batchTracking,
      brand_name: isPharmacy ? String(formData.get('brand_name') ?? '').trim() || undefined : undefined,
      generic_name: isPharmacy ? String(formData.get('generic_name') ?? '').trim() || undefined : undefined,
      active_ingredient: isPharmacy ? String(formData.get('active_ingredient') ?? '').trim() || undefined : undefined,
      strength: isPharmacy ? String(formData.get('strength') ?? '').trim() || undefined : undefined,
      dosage_form: isPharmacy ? String(formData.get('dosage_form') ?? '').trim() || undefined : undefined,
      pack_size: isPharmacy ? String(formData.get('pack_size') ?? '').trim() || undefined : undefined,
      unit_of_sale: isPharmacy ? String(formData.get('unit_of_sale') ?? '').trim() || undefined : undefined,
      manufacturer: isPharmacy ? String(formData.get('manufacturer') ?? '').trim() || undefined : undefined,
      registration_number: isPharmacy ? String(formData.get('registration_number') ?? '').trim() || undefined : undefined,
      medicine_classification: isPharmacy ? String(formData.get('medicine_classification') ?? 'otc') as 'otc' | 'prescription' | 'controlled' : undefined,
      prescription_required: isPharmacy && (String(formData.get('medicine_classification') ?? 'otc') !== 'otc' || formData.get('prescription_required') === 'on'),
      variant_attributes: Object.keys(variant_attributes).length ? variant_attributes : undefined,
      bulk_pricing: bulk_pricing.length ? bulk_pricing : undefined,
      product_type: productType,
      service_duration_minutes: productType === 'service' ? Number(formData.get('service_duration_minutes') || 30) : undefined,
      service_buffer_minutes: productType === 'service' ? Number(formData.get('service_buffer_minutes') || 0) : undefined,
      commission_type: String(formData.get('commission_type') ?? '') ? String(formData.get('commission_type')) as 'percentage' | 'fixed' : undefined,
      commission_value: String(formData.get('commission_value') ?? '').trim() ? Number(formData.get('commission_value')) : undefined,
      name: String(formData.get('name') ?? '').trim(),
      category: String(formData.get('category') ?? '').trim() || undefined,
      unit_price: unitPrice,
      cost_price: costPrice === null || String(costPrice).trim() === '' ? undefined : Number(costPrice),
      quantity_on_hand: productType === 'goods' && trackingMode === 'shared' && !batchTracking ? Number(formData.get('quantity_on_hand') || 0) : 0,
      reorder_level: Number(formData.get('reorder_level') || 0),
    })
  }

  const rows = (productsQuery.data ?? []).map((product) => ({
    ...product,
    price: formatCurrency(Number(product.unit_price), currency),
    stock_display: product.product_type === 'service' ? 'Not tracked' : product.quantity_on_hand,
    stock_status: product.product_type === 'service'
      ? 'Service'
      : product.quantity_available <= 0 ? 'Out of stock' : product.quantity_available <= product.reorder_level ? 'Low stock' : 'In stock',
  }))

  return (
    <section className={`module-page products-page ${isPharmacy ? 'pharmacy-products-page' : 'general-products-page'} w-full`}>
      <div className="mb-6 flex w-full items-center justify-between gap-4">
        <div>
          <p className="eyebrow">Catalog</p>
          <h1>{isPharmacy ? 'Medicines & products' : 'Products'}</h1>
        </div>
        <Button variant="primary" onClick={() => setIsCreateOpen((open) => !open)}>
          {isCreateOpen ? 'Close form' : isPharmacy ? 'Add medicine' : 'Add product'}
        </Button>
      </div>

      {isCreateOpen && (
        <Card className="mb-5 w-full p-5">
          <form className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" onSubmit={submitProduct}>
            <label className="grid gap-1 text-sm font-medium text-graphite">SKU<input required name="sku" maxLength={64} title="Use letters, numbers, and hyphens only" className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
            <label className="grid gap-1 text-sm font-medium text-graphite">Catalog item type
              <select value={productType} onChange={(event) => { setProductType(event.target.value === 'service' ? 'service' : 'goods'); setTrackingMode('shared') }} className="rounded-xl border border-graphite/10 bg-white px-3 py-2.5">
                <option value="goods">Stocked product / menu item</option>
                <option value="service">Service (no stock)</option>
              </select>
            </label>
            <label className="grid gap-1 text-sm font-medium text-graphite">Barcode tracking
              <select disabled={productType === 'service' || batchTracking} value={trackingMode} onChange={(event) => setTrackingMode(event.target.value === 'individual' ? 'individual' : 'shared')} className="rounded-xl border border-graphite/10 bg-white px-3 py-2.5 disabled:opacity-50">
                <option value="shared">One shared barcode per product</option>
                <option value="individual">Unique barcode per physical unit</option>
              </select>
            </label>
            {productType === 'goods' && isPharmacy && <label className="flex items-center gap-2 text-sm font-medium text-graphite">
              <input type="checkbox" checked={batchTracking} onChange={(event) => { setBatchTracking(event.target.checked); if (event.target.checked) setTrackingMode('shared') }} className="h-4 w-4 accent-lime-dark" />
              Track pharmacy batch and expiry
            </label>}
            {productType === 'goods' && trackingMode === 'shared' && <label className="grid gap-1 text-sm font-medium text-graphite">Product barcode (shared)<input name="barcode" maxLength={100} autoFocus placeholder="Scan barcode" onKeyDown={(event) => { if (event.key === 'Enter') event.preventDefault() }} className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>}
            <label className="grid gap-1 text-sm font-medium text-graphite">Product name<input required name="name" className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
            <label className="grid gap-1 text-sm font-medium text-graphite">Category<input name="category" className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
            {productType === 'goods' && isPharmacy && <>
              <p className="eyebrow sm:col-span-2 xl:col-span-3">Medicine details</p>
              <label className="grid gap-1 text-sm font-medium text-graphite">Brand name<input name="brand_name" className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
              <label className="grid gap-1 text-sm font-medium text-graphite">Generic name<input name="generic_name" className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
              <label className="grid gap-1 text-sm font-medium text-graphite">Active ingredient<input name="active_ingredient" className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
              <label className="grid gap-1 text-sm font-medium text-graphite">Strength<input name="strength" placeholder="e.g. 500 mg" className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
              <label className="grid gap-1 text-sm font-medium text-graphite">Dosage form<input name="dosage_form" placeholder="tablet, capsule, syrup" className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
              <label className="grid gap-1 text-sm font-medium text-graphite">Pack size<input name="pack_size" placeholder="e.g. 20 tablets" className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
              <label className="grid gap-1 text-sm font-medium text-graphite">Unit of sale<input name="unit_of_sale" placeholder="tablet, bottle, pack" className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
              <label className="grid gap-1 text-sm font-medium text-graphite">Manufacturer<input name="manufacturer" className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
              <label className="grid gap-1 text-sm font-medium text-graphite">Registration number<input name="registration_number" className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
              <label className="grid gap-1 text-sm font-medium text-graphite">Classification<select name="medicine_classification" defaultValue="otc" className="rounded-xl border border-graphite/10 bg-white px-3 py-2.5"><option value="otc">Over the counter</option><option value="prescription">Prescription</option><option value="controlled">Controlled</option></select></label>
              <label className="flex items-center gap-2 text-sm font-medium text-graphite"><input type="checkbox" name="prescription_required" className="h-4 w-4 accent-lime-dark" />Requires prescription verification</label>
            </>}
            <label className="grid gap-1 text-sm font-medium text-graphite">Selling price ({currency})<input required name="unit_price" type="number" min="0" step="1" inputMode="numeric" className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
            <label className="grid gap-1 text-sm font-medium text-graphite">Cost price ({currency})<input name="cost_price" type="number" min="0" step="1" inputMode="numeric" className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
            {productType === 'goods' && enabledModules.includes('variants') && <label className="grid gap-1 text-sm font-medium text-graphite">Variant attributes<input name="variant_attributes" placeholder="Size=M, Colour=Blue" className="rounded-xl border border-graphite/10 px-3 py-2.5" /><span className="text-xs font-normal text-graphite/55">Stored on this sellable SKU.</span></label>}
            {productType === 'goods' && enabledModules.includes('wholesale') && <label className="grid gap-1 text-sm font-medium text-graphite">Wholesale price tiers<input name="bulk_pricing" placeholder="10:90, 50:80" className="rounded-xl border border-graphite/10 px-3 py-2.5" /><span className="text-xs font-normal text-graphite/55">Quantity:unit price; checkout applies the best qualifying tier.</span></label>}
            {productType === 'service'
              ? <>
                <label className="grid gap-1 text-sm font-medium text-graphite">Service duration (minutes)<input name="service_duration_minutes" type="number" min="1" max="1440" step="1" defaultValue="30" className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
                {enabledModules.includes('appointments') && <label className="grid gap-1 text-sm font-medium text-graphite">Booking buffer (minutes)<input name="service_buffer_minutes" type="number" min="0" max="240" step="1" defaultValue="0" className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>}
                {enabledModules.includes('appointments') && <>
                  <label className="grid gap-1 text-sm font-medium text-graphite">Commission type<select name="commission_type" defaultValue="" className="rounded-xl border border-graphite/10 bg-white px-3 py-2.5"><option value="">No commission</option><option value="percentage">Percentage of sale</option><option value="fixed">Fixed amount per unit</option></select></label>
                  <label className="grid gap-1 text-sm font-medium text-graphite">Commission value<input name="commission_value" type="number" min="0" step="0.01" placeholder="e.g. 10" className="rounded-xl border border-graphite/10 px-3 py-2.5" /><span className="text-xs font-normal text-graphite/55">Percentages are capped at 100%; fixed rules use {currency} per unit.</span></label>
                </>}
              </>
              : batchTracking
              ? <p className="text-sm text-graphite/65 sm:col-span-2 xl:col-span-3">Initial quantity is received with a lot number and expiry date in Inventory so checkout can consume the earliest-expiring stock first.</p>
              : trackingMode === 'shared'
              ? <label className="grid gap-1 text-sm font-medium text-graphite">Initial quantity on hand<input name="quantity_on_hand" type="number" min="0" max="2147483647" step="1" defaultValue="0" className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
              : <p className="text-sm text-graphite/65 sm:col-span-2 xl:col-span-3">After creating this product, receive each serialized unit in Inventory or Purchases by scanning its unique barcode. Stock is counted from those scanned units.</p>}
            <label className="grid gap-1 text-sm font-medium text-graphite">Reorder level<input name="reorder_level" type="number" min="0" step="1" defaultValue="0" className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
            <div className="flex items-center gap-3 sm:col-span-2 xl:col-span-3">
              <Button type="submit" disabled={createMutation.isPending}>{createMutation.isPending ? 'Saving...' : 'Save product'}</Button>
              {(formError || createMutation.isError) && <span role="alert" className="text-sm text-danger">{formError || 'Could not save product. Check the SKU, price, and enabled industry modules.'}</span>}
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
            { header: 'Type', accessor: 'product_type' },
            { header: 'Barcode', accessor: 'barcode', render: (product) => product.product_type === 'service' ? '—' : product.barcode_tracking_enabled ? 'Unique per unit' : product.barcode || '—' },
            { header: 'Category', accessor: 'category' },
            { header: 'Selling Price', accessor: 'price' },
            { header: 'Stock', accessor: 'stock_display' },
            { header: 'Status', accessor: 'stock_status' },
          ]}
        />
      </Card>
    </section>
  )
}
