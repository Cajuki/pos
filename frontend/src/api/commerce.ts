import { apiClient } from './client'

export interface Product {
  id: number
  sku: string
  name: string
  category: string | null
  category_id?: number | null
  barcode?: string | null
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
  id?: number
  product_id: number
  sku: string
  product_name: string
  quantity: number
  unit_price: string
  unit_cost: string | null
  line_total: string
}

export interface Sale {
  id: number
  receipt_number: string
  payment_method: 'cash' | 'mpesa' | 'card' | 'bank' | 'credit' | string
  status: string
  subtotal: string
  total: string
  created_at: string
  items: SaleItem[]
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

export async function createProduct(product: {
  sku: string
  name: string
  category?: string
  barcode?: string
  unit_price: string
  cost_price?: string
  reorder_level?: number
}): Promise<Product> {
  const response = await apiClient.post<{ data: Product }>('/products', product)
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
  items: Array<{ product_id: number; quantity: number }>
}): Promise<Sale> {
  const response = await apiClient.post<{ data: Sale }>('/sales', payload)
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