import { apiClient } from './client'

export interface Product {
  id: number
  sku: string
  name: string
  category: string | null
  category_id?: number | null
  barcode?: string | null
  barcode_tracking_enabled: boolean
  description: string | null
  unit_price: string
  cost_price: string | null
  reorder_level: number
  is_active: boolean
  quantity_on_hand: number
}

export interface Category {
  id: number
  name: string
  description: string | null
  is_active: boolean
  products_count?: number
}

export interface InventoryItem {
  product_id: number
  sku: string
  name: string
  unit_price: string
  quantity_on_hand: number
  reorder_level: number
  is_low_stock: boolean
  barcode_tracking_enabled: boolean
}

export interface StockMovement {
  id: number
  type: string
  quantity_change: number
  quantity_before: number
  quantity_after: number
  reason: string | null
  created_at: string
  product: { id: number; sku: string; name: string }
}

export interface SaleItem {
  id: number
  product_id: number
  sku: string
  product_name: string
  quantity: number
  unit_price: string
  unit_cost: string | null
  line_total: string
  barcodes?: string[]
}

export interface Sale {
  id: number
  customer_id: number | null
  customer: Pick<Customer, 'id' | 'name' | 'phone' | 'email'> | null
  receipt_number: string
  payment_method: 'cash' | 'mpesa' | 'card' | 'bank' | 'credit' | string
  status: string
  subtotal: string
  discount_amount: string
  tax_amount: string
  tax_rate: string
  total: string
  created_at: string
  items: SaleItem[]
}

export interface PosPreferences {
  business_phone: string
  business_email: string
  business_address: string
  tax_number: string
  tax_enabled: boolean
  tax_rate: string
  tax_inclusive: boolean
  discount_enabled: boolean
  max_discount_percent: string
  payment_methods: Array<'cash' | 'mpesa' | 'card' | 'bank' | 'credit'>
  default_payment_method: 'cash' | 'mpesa' | 'card' | 'bank' | 'credit'
  receipt_show_business_details: boolean
  receipt_footer: string
}

export interface BusinessSettings {
  id: string
  name: string
  currency: string
  timezone: string
  role: string
  settings: PosPreferences
}

export interface Customer {
  id: number
  name: string
  email: string | null
  phone: string | null
  address: string | null
  status: string
  sales_count?: number
  created_at?: string
}

export interface Supplier {
  id: number
  name: string
  email: string | null
  phone: string | null
  address: string | null
  status: string
  purchase_orders_count?: number
  created_at?: string
}

export interface PurchaseItem {
  id: number
  product_id: number
  sku: string
  product_name: string
  quantity_ordered: number
  quantity_received: number
  unit_cost: string
  line_total: string
}

export interface PurchaseOrder {
  id: number
  reference_number: string
  status: string
  subtotal: string
  total: string
  ordered_at: string
  received_at: string | null
  notes: string | null
  supplier: Supplier | null
  items: PurchaseItem[]
}

export interface ExpenseCategory {
  id: number
  name: string
  expenses_count?: number
}

export interface Expense {
  id: number
  reference_number: string | null
  description: string
  payment_method: string
  amount: string
  incurred_on: string
  notes: string | null
  category: ExpenseCategory | null
  creator?: { id: number; name: string } | null
}

export interface CashRegisterSession {
  id: number
  cash_register_id: number
  user_id: number
  status: 'open' | 'closed'
  opening_balance: string
  expected_cash: string
  actual_cash: string | null
  difference: string | null
  opened_at: string
  closed_at: string | null
  closing_notes: string | null
  user?: { id: number; name: string }
}

export interface CashRegisterState {
  register: { id: number; name: string; code: string; is_active: boolean }
  current_session: CashRegisterSession | null
  is_open: boolean
}

export interface ReportSummary {
  today: {
    revenue: number
    transactions: number
    profit_estimate: number
  }
  period: {
    revenue: number
    transactions: number
  }
  inventory: {
    total_products: number
    total_units: number
    inventory_value: number
    low_stock_count: number
  }
  payment_breakdown: Array<{
    method: string
    count: number
    total: number
  }>
  sales_trend: Array<{
    date: string
    total: number
    transactions: number
  }>
  top_products: Array<{
    product_name: string
    sku: string
    units_sold: number
    revenue: number
  }>
}

export interface StaffMember {
  id: number
  name: string
  email: string
  role: string
}

interface PaginatedResponse<T> {
  data: T[]
  meta: { current_page: number; last_page: number; total: number }
}

// Products
export async function getProducts(search = '', active?: boolean): Promise<Product[]> {
  const response = await apiClient.get<PaginatedResponse<Product>>('/products', {
    params: { ...(search ? { search } : {}), ...(active === undefined ? {} : { active: Number(active) }) },
  })
  return response.data.data
}

export interface ProductBarcodeLookup {
  product: Product
  tracked_barcode: string | null
  status: 'in_stock' | 'sold' | 'product'
}

export async function getProductByBarcode(barcode: string): Promise<ProductBarcodeLookup | null> {
  const response = await apiClient.get<{ data: ProductBarcodeLookup | null }>(`/products/barcode/${encodeURIComponent(barcode)}`)
  return response.data.data
}

export async function createProduct(product: {
  sku: string
  barcode?: string
  name: string
  category?: string
  unit_price: number
  cost_price?: number
  quantity_on_hand?: number
  reorder_level?: number
}): Promise<Product> {
  const response = await apiClient.post<{ data: Product }>('/products', product)
  return response.data.data
}

export async function getStaff(): Promise<StaffMember[]> {
  const response = await apiClient.get<{ data: StaffMember[] }>('/business/staff')
  return response.data.data
}

export async function createStaff(payload: {
  name: string
  email: string
  password: string
  role: string
}): Promise<StaffMember> {
  const response = await apiClient.post<{ data: StaffMember }>('/business/staff', payload)
  return response.data.data
}

// Categories
export async function getCategories(): Promise<Category[]> {
  const response = await apiClient.get<{ data: Category[] }>('/categories')
  return response.data.data
}

export async function createCategory(payload: { name: string; description?: string }): Promise<Category> {
  const response = await apiClient.post<{ data: Category }>('/categories', payload)
  return response.data.data
}

// Inventory
export async function getInventory(): Promise<InventoryItem[]> {
  const response = await apiClient.get<PaginatedResponse<InventoryItem>>('/inventory')
  return response.data.data
}

export async function adjustStock(payload: {
  product_id: number
  quantity_change: number
  reason: string
}) {
  const response = await apiClient.post('/inventory/adjustments', payload)
  return response.data.data
}

export async function receiveBarcodeStock(payload: { product_id: number; barcode: string }) {
  const response = await apiClient.post<{ data: { barcode: string; product_id: number; product_name: string; quantity_on_hand: number } }>('/inventory/barcodes/receive', payload)
  return response.data.data
}

export async function getStockMovements(): Promise<StockMovement[]> {
  const response = await apiClient.get<PaginatedResponse<StockMovement>>('/inventory/movements')
  return response.data.data
}

// Sales & POS
export async function getSales(): Promise<Sale[]> {
  const response = await apiClient.get<PaginatedResponse<Sale>>('/sales')
  return response.data.data
}

export async function createSale(payload: {
  payment_method: 'cash' | 'mpesa' | 'card' | 'bank' | 'credit'
  customer_id?: number
  discount_percent?: number
  items: Array<{ product_id: number; quantity: number; barcodes?: string[] }>
}): Promise<Sale> {
  const response = await apiClient.post<{ data: Sale }>('/sales', payload)
  return response.data.data
}

export async function getBusinessSettings(): Promise<BusinessSettings> {
  const response = await apiClient.get<{ data: BusinessSettings }>('/business/settings')
  return response.data.data
}

export async function updateBusinessSettings(settings: BusinessSettings): Promise<BusinessSettings> {
  const response = await apiClient.put<{ data: BusinessSettings }>('/business/settings', {
    name: settings.name,
    currency: settings.currency,
    timezone: settings.timezone,
    settings: settings.settings,
  })
  return response.data.data
}

// Customers
export async function getCustomers(search = ''): Promise<Customer[]> {
  const response = await apiClient.get<PaginatedResponse<Customer>>('/customers', {
    params: search ? { search } : {},
  })
  return response.data.data
}

export async function createCustomer(payload: {
  name: string
  phone?: string
  email?: string
  address?: string
}): Promise<Customer> {
  const response = await apiClient.post<{ data: Customer }>('/customers', payload)
  return response.data.data
}

// Suppliers
export async function getSuppliers(): Promise<Supplier[]> {
  const response = await apiClient.get<PaginatedResponse<Supplier>>('/suppliers')
  return response.data.data
}

export async function createSupplier(payload: {
  name: string
  phone?: string
  email?: string
  address?: string
}): Promise<Supplier> {
  const response = await apiClient.post<{ data: Supplier }>('/suppliers', payload)
  return response.data.data
}

// Purchases
export async function getPurchases(): Promise<PurchaseOrder[]> {
  const response = await apiClient.get<PaginatedResponse<PurchaseOrder>>('/purchases')
  return response.data.data
}

export async function createPurchase(payload: {
  supplier_id?: number
  notes?: string
  items: Array<{ product_id: number; quantity: number; unit_cost: number }>
}): Promise<PurchaseOrder> {
  const response = await apiClient.post<{ data: PurchaseOrder }>('/purchases', payload)
  return response.data.data
}

// Expenses
export async function getExpenses(): Promise<Expense[]> {
  const response = await apiClient.get<PaginatedResponse<Expense>>('/expenses')
  return response.data.data
}

export async function getExpenseCategories(): Promise<ExpenseCategory[]> {
  const response = await apiClient.get<{ data: ExpenseCategory[] }>('/expenses/categories')
  return response.data.data
}

export async function createExpense(payload: {
  expense_category_id?: number
  description: string
  payment_method: string
  amount: number
  incurred_on: string
  notes?: string
}): Promise<Expense> {
  const response = await apiClient.post<{ data: Expense }>('/expenses', payload)
  return response.data.data
}

// Cash Registers
export async function getCashRegisterStatus(): Promise<CashRegisterState> {
  const response = await apiClient.get<{ data: CashRegisterState }>('/cash-registers/current')
  return response.data.data
}

export async function openCashRegister(payload: { opening_balance: number }): Promise<CashRegisterSession> {
  const response = await apiClient.post<{ data: CashRegisterSession }>('/cash-registers/open', payload)
  return response.data.data
}

export async function closeCashRegister(payload: { actual_cash: number; closing_notes?: string }): Promise<CashRegisterSession> {
  const response = await apiClient.post<{ data: CashRegisterSession }>('/cash-registers/close', payload)
  return response.data.data
}

// Reports Summary
export async function getReportSummary(period = '7days'): Promise<ReportSummary> {
  const response = await apiClient.get<{ data: ReportSummary }>('/reports/summary', {
    params: { period },
  })
  return response.data.data
}