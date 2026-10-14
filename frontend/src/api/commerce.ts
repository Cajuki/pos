import { apiClient } from './client'

export interface Product {
  id: number
  sku: string
  name: string
  category: string | null
  category_id?: number | null
  barcode?: string | null
  barcode_tracking_enabled: boolean
  product_type: 'goods' | 'service'
  service_duration_minutes: number | null
  service_buffer_minutes?: number
  commission_type?: 'percentage' | 'fixed' | null
  commission_value?: string | null
  variant_attributes?: Record<string, string> | null
  bulk_pricing?: Array<{ minimum_quantity: number; unit_price: number }> | null
  batch_tracking_enabled: boolean
  brand_name?: string | null
  generic_name?: string | null
  active_ingredient?: string | null
  strength?: string | null
  dosage_form?: string | null
  pack_size?: string | null
  unit_of_sale?: string | null
  manufacturer?: string | null
  registration_number?: string | null
  medicine_classification?: 'otc' | 'prescription' | 'controlled' | null
  prescription_required?: boolean
  description: string | null
  unit_price: string
  cost_price: string | null
  reorder_level: number
  is_active: boolean
  quantity_on_hand: number
  quantity_available: number
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
  barcode: string | null
  name: string
  unit_price: string
  quantity_on_hand: number
  reorder_level: number
  is_low_stock: boolean
  barcode_tracking_enabled: boolean
  batch_tracking_enabled?: boolean
  quantity_reserved: number
  quantity_available: number
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

export interface InventoryBatch {
  id: number
  product_id: number
  lot_number: string
  expires_on: string
  quantity_received: number
  quantity_remaining: number
  unit_cost: string | null
  product: { id: number; sku: string; name: string }
}

export interface InventoryBatchExpirySummary {
  as_of: string
  expired_units: number
  expiring_units: number
}

export interface SaleItem {
  id: number
  product_id: number
  sku: string
  barcode: string | null
  product_name: string
  quantity: number
  refunded_quantity: number
  unit_price: string
  unit_cost: string | null
  line_total: string
  barcodes?: string[]
  performed_by_user_id?: number | null
}

export interface Sale {
  id: number
  cash_register_id?: number
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
  refunded_total: string
  cash_received: string | null
  change_given: string | null
  created_at: string
  items: SaleItem[]
  order_type?: string | null
  table_name?: string | null
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
  enabled_modules: string[]
  role_permissions: Record<string, string[]>
}

export interface BusinessSettings {
  id: string
  name: string
  currency: string
  timezone: string
  industry: string
  modules: string[]
  module_options: string[]
  role: string
  settings: PosPreferences
  subscription: {
    status: string
    starts_at: string
    ends_at: string | null
    trial_ends_at: string | null
    plan: {
      code: string
      name: string
      features: Record<string, boolean>
      limits: Record<string, number | null>
    }
  } | null
}

export interface Appointment {
  id: number
  staff_user_id: number | null
  staff_profile_id: number | null
  branch_id: number | null
  starts_at: string
  duration_minutes: number
  buffer_minutes: number
  booking_source: 'appointment' | 'walk_in'
  checked_in_at: string | null
  status: 'booked' | 'in_progress' | 'completed' | 'cancelled' | 'no_show'
  notes: string | null
  customer: Pick<Customer, 'id' | 'name' | 'phone'> | null
  service: Pick<Product, 'id' | 'name' | 'unit_price'>
  staff: { id: number; name: string } | null
  staff_profile?: { id: number; name: string } | null
}

export interface StaffSchedule {
  id: number
  branch_id: number
  staff_user_id: number
  staff_profile_id?: number | null
  day_of_week: number
  is_working: boolean
  starts_at: string
  ends_at: string
  break_starts_at: string | null
  break_ends_at: string | null
}

export interface CommissionEntry {
  id: number
  staff_user_id: number
  basis_amount: string
  earned_amount: string
  reversed_amount: string
  status: 'pending' | 'approved' | 'paid' | 'reversed'
  paid_at: string | null
  payout_reference: string | null
  created_at: string
  staff: { id: number; name: string } | null
  staff_profile?: { id: number; name: string } | null
  sale: { id: number; receipt_number: string; created_at: string }
  sale_item: { id: number; product_name: string; quantity: number; line_total: string }
}

export interface RestaurantOrder {
  id: number
  restaurant_table_id?: number | null
  order_type: 'dine_in' | 'takeaway' | 'delivery'
  table_name: string | null
  table?: RestaurantTable | null
  status: 'open' | 'preparing' | 'ready' | 'served' | 'paid' | 'cancelled'
  notes: string | null
  customer: Pick<Customer, 'id' | 'name'> | null
  items: Array<{
    id: number
    product_id: number
    quantity: number
    unit_price: string
    notes: string | null
    product: Pick<Product, 'id' | 'sku' | 'name' | 'product_type' | 'unit_price'>
  }>
  sale: Pick<Sale, 'id' | 'receipt_number' | 'total'> | null
  created_at: string
}

export interface RestaurantTable {
  id: number
  branch_id: number
  name: string
  area: string | null
  seating_capacity: number
  status: 'available' | 'occupied' | 'reserved' | 'cleaning'
  is_active: boolean
}

export interface Branch {
  id: number
  name: string
  code: string
  is_default: boolean
  is_active: boolean
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
  product_barcodes?: Array<{ barcode: string; status: string }>
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
  register?: CashRegister
}

export interface CashRegister {
  id: number
  branch_id: number | null
  name: string
  code: string
  is_active: boolean
}

export interface CashRegisterState {
  register: CashRegister
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
  id: number | string
  name: string
  email: string
  role: string
  status: 'active' | 'invited'
}

export interface SalonStaffProfile {
  id: number
  name: string
  email: string | null
  phone: string | null
  employee_id: string | null
  employment_type: string
  employment_started_on: string | null
  employment_status: 'active' | 'inactive' | 'on_leave'
  account_status: 'active' | 'profile_only'
  job_titles: Array<{ id: number; name: string; is_primary: boolean }>
  branches: Array<{ id: number; name: string }>
  services: Array<{ id: number; name: string }>
  commission_type: 'percentage' | 'fixed' | null
  commission_value: string | null
}

export interface SalonJobTitle {
  id: number
  name: string
  slug: string
  default_permissions: string[] | null
  is_predefined: boolean
}

export async function getSalonStaff(params: { search?: string; status?: string } = {}): Promise<{ data: SalonStaffProfile[]; summary: { total: number; active: number; on_duty: number; on_leave: number } }> {
  const response = await apiClient.get<{ data: SalonStaffProfile[]; meta: { summary: { total: number; active: number; on_duty: number; on_leave: number } } }>('/salon/staff', { params })
  return { data: response.data.data, summary: response.data.meta.summary }
}

export async function getSalonJobTitles(): Promise<SalonJobTitle[]> {
  const response = await apiClient.get<{ data: SalonJobTitle[] }>('/salon/job-titles')
  return response.data.data
}

export async function createSalonJobTitle(payload: { name: string; default_permissions?: string[] }): Promise<SalonJobTitle> {
  const response = await apiClient.post<{ data: SalonJobTitle }>('/salon/job-titles', payload)
  return response.data.data
}

export async function createSalonStaff(payload: {
  name: string
  phone?: string
  email?: string
  employee_id?: string
  employment_type: string
  employment_started_on?: string
  employment_status: string
  job_title_ids: number[]
  branch_ids: number[]
  service_ids: number[]
  permission_overrides?: string[]
  commission_type?: 'percentage' | 'fixed'
  commission_value?: number
}): Promise<SalonStaffProfile> {
  const response = await apiClient.post<{ data: SalonStaffProfile }>('/salon/staff', payload)
  return response.data.data
}

export async function updateSalonStaffStatus(id: number, employment_status: SalonStaffProfile['employment_status']): Promise<void> {
  await apiClient.patch(`/salon/staff/${id}/status`, { employment_status })
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
  barcode_tracking_enabled?: boolean
  batch_tracking_enabled?: boolean
  product_type?: 'goods' | 'service'
  service_duration_minutes?: number
  service_buffer_minutes?: number
  commission_type?: 'percentage' | 'fixed'
  commission_value?: number
  variant_attributes?: Record<string, string>
  bulk_pricing?: Array<{ minimum_quantity: number; unit_price: number }>
  brand_name?: string
  generic_name?: string
  active_ingredient?: string
  strength?: string
  dosage_form?: string
  pack_size?: string
  unit_of_sale?: string
  manufacturer?: string
  registration_number?: string
  medicine_classification?: 'otc' | 'prescription' | 'controlled'
  prescription_required?: boolean
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

export async function getInventoryBatches(): Promise<{
  batches: InventoryBatch[]
  expirySummary: InventoryBatchExpirySummary
}> {
  const response = await apiClient.get<PaginatedResponse<InventoryBatch> & {
    meta: PaginatedResponse<InventoryBatch>['meta'] & { expiry_alerts: InventoryBatchExpirySummary }
  }>('/inventory/batches')
  return {
    batches: response.data.data,
    expirySummary: response.data.meta.expiry_alerts,
  }
}

export async function receiveInventoryBatch(payload: {
  product_id: number
  lot_number: string
  expires_on: string
  quantity: number
  unit_cost?: number
}): Promise<InventoryBatch> {
  const response = await apiClient.post<{ data: InventoryBatch }>('/inventory/batches/receive', payload)
  return response.data.data
}

export async function getAppointments(filters: { from?: string; to?: string; staff_user_id?: number; staff_profile_id?: number; branch_id?: number } = {}): Promise<Appointment[]> {
  const response = await apiClient.get<PaginatedResponse<Appointment>>('/appointments', { params: filters })
  return response.data.data
}

export async function createAppointment(payload: {
  customer_id?: number
  service_product_id: number
  staff_user_id?: number
  staff_profile_id?: number
  starts_at?: string
  branch_id?: number
  walk_in?: boolean
  duration_minutes?: number
  notes?: string
}): Promise<Appointment> {
  const response = await apiClient.post<{ data: Appointment }>('/appointments', payload)
  return response.data.data
}

export async function updateAppointment(id: number, payload: {
  customer_id?: number
  service_product_id: number
  staff_user_id?: number
  staff_profile_id?: number
  starts_at: string
  branch_id?: number
  duration_minutes?: number
  notes?: string
}): Promise<Appointment> {
  const response = await apiClient.put<{ data: Appointment }>(`/appointments/${id}`, payload)
  return response.data.data
}

export async function checkInAppointment(id: number): Promise<Appointment> {
  const response = await apiClient.post<{ data: Appointment }>(`/appointments/${id}/check-in`)
  return response.data.data
}

export async function getStaffSchedules(filters: { branch_id?: number; staff_user_id?: number } = {}): Promise<StaffSchedule[]> {
  const response = await apiClient.get<{ data: StaffSchedule[] }>('/staff-schedules', { params: filters })
  return response.data.data
}

export async function saveStaffSchedule(payload: Omit<StaffSchedule, 'id'>): Promise<StaffSchedule> {
  const response = await apiClient.put<{ data: StaffSchedule }>('/staff-schedules', payload)
  return response.data.data
}

export async function getCommissionEntries(): Promise<{ data: CommissionEntry[]; summary: { earned: string; reversed: string; pending: string; approved: string; paid: string } }> {
  const response = await apiClient.get<{ data: CommissionEntry[]; meta: { summary: { earned: string; reversed: string; pending: string; approved: string; paid: string } } }>('/commissions')
  return { data: response.data.data, summary: response.data.meta.summary }
}

export async function updateCommissionStatus(id: number, status: 'approved' | 'paid', payout_reference?: string): Promise<CommissionEntry> {
  const response = await apiClient.patch<{ data: CommissionEntry }>(`/commissions/${id}/status`, { status, payout_reference })
  return response.data.data
}

export async function updateAppointmentStatus(id: number, status: Appointment['status']): Promise<Appointment> {
  const response = await apiClient.patch<{ data: Appointment }>(`/appointments/${id}/status`, { status })
  return response.data.data
}

export async function getRestaurantOrders(): Promise<RestaurantOrder[]> {
  const response = await apiClient.get<PaginatedResponse<RestaurantOrder>>('/restaurant-orders')
  return response.data.data
}

export async function getBranches(): Promise<Branch[]> {
  const response = await apiClient.get<{ data: Branch[] }>('/branches')
  return response.data.data
}

export async function getRestaurantTables(branchId?: number): Promise<RestaurantTable[]> {
  const response = await apiClient.get<{ data: RestaurantTable[] }>('/restaurant/tables', {
    params: branchId ? { branch_id: branchId } : {},
  })
  return response.data.data
}

export async function createRestaurantTable(payload: {
  branch_id: number
  name: string
  area?: string
  seating_capacity: number
}): Promise<RestaurantTable> {
  const response = await apiClient.post<{ data: RestaurantTable }>('/restaurant/tables', payload)
  return response.data.data
}

export async function updateRestaurantTableStatus(id: number, status: 'available' | 'reserved' | 'cleaning'): Promise<RestaurantTable> {
  const response = await apiClient.patch<{ data: RestaurantTable }>(`/restaurant/tables/${id}`, { status })
  return response.data.data
}

export async function createRestaurantOrder(payload: {
  cash_register_id?: number
  customer_id?: number
  order_type: RestaurantOrder['order_type']
  restaurant_table_id?: number
  table_name?: string
  notes?: string
  items: Array<{ product_id: number; quantity: number; notes?: string }>
}): Promise<RestaurantOrder> {
  const response = await apiClient.post<{ data: RestaurantOrder }>('/restaurant-orders', payload)
  return response.data.data
}

export async function updateRestaurantOrderStatus(id: number, status: 'preparing' | 'ready' | 'served' | 'cancelled'): Promise<RestaurantOrder> {
  const response = await apiClient.patch<{ data: RestaurantOrder }>(`/restaurant-orders/${id}/status`, { status })
  return response.data.data
}

export async function getStaff(): Promise<StaffMember[]> {
  const response = await apiClient.get<{ data: StaffMember[] }>('/business/staff')
  return response.data.data
}

export async function getBusinessProviders(): Promise<Array<{ id: string; staff_user_id: number | null; staff_profile_id: number | null; name: string }>> {
  const response = await apiClient.get<{ data: Array<{ id: string; staff_user_id: number | null; staff_profile_id: number | null; name: string }> }>('/business/providers')
  return response.data.data
}

export async function createStaff(payload: {
  name: string
  email: string
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

const checkoutIdempotencyKeys = new Map<string, string>()

export async function createSale(payload: {
  cash_register_id?: number
  payment_method: 'cash' | 'mpesa' | 'card' | 'bank' | 'credit'
  cash_received?: string
  customer_id?: number
  restaurant_order_id?: number
  appointment_id?: number
  order_type?: 'dine_in' | 'takeaway' | 'delivery'
  table_name?: string
  discount_percent?: number
  items: Array<{ product_id: number; quantity: number; barcodes?: string[]; performed_by_user_id?: number }>
}): Promise<Sale> {
  const payloadSignature = JSON.stringify(payload)
  let idempotencyKey = checkoutIdempotencyKeys.get(payloadSignature)
  if (!idempotencyKey) {
    idempotencyKey = crypto.randomUUID()
    checkoutIdempotencyKeys.set(payloadSignature, idempotencyKey)
    if (checkoutIdempotencyKeys.size > 100) {
      const oldestSignature = checkoutIdempotencyKeys.keys().next().value
      if (oldestSignature) checkoutIdempotencyKeys.delete(oldestSignature)
    }
  }

  const response = await apiClient.post<{ data: Sale }>('/sales', payload, {
    headers: { 'Idempotency-Key': idempotencyKey },
  })
  checkoutIdempotencyKeys.delete(payloadSignature)
  return response.data.data
}

export interface Refund {
  id: number
  sale_id: number
  reference_number: string
  status: string
  amount: string
  reason: string
  created_at: string
  items: Array<{ sale_item_id: number; quantity: number; unit_amount: string; line_amount: string }>
}

export async function createCashRefund(saleId: number, payload: {
  reason: string
  items: Array<{ sale_item_id: number; quantity: number; barcodes?: string[] }>
}): Promise<Refund> {
  const response = await apiClient.post<{ data: Refund }>(`/sales/${saleId}/refunds`, payload)
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
  items: Array<{ product_id: number; quantity: number; unit_cost: number; barcodes?: string[] }>
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
export async function getCashRegisters(): Promise<CashRegisterState[]> {
  const response = await apiClient.get<{ data: CashRegisterState[] }>('/cash-registers')
  return response.data.data
}

export async function createCashRegister(payload: { name: string; code: string }): Promise<CashRegisterState> {
  const response = await apiClient.post<{ data: CashRegisterState }>('/cash-registers', payload)
  return response.data.data
}

export async function getCashRegisterStatus(registerId?: number): Promise<CashRegisterState> {
  const response = await apiClient.get<{ data: CashRegisterState }>('/cash-registers/current', {
    params: registerId ? { register_id: registerId } : {},
  })
  return response.data.data
}

export async function openCashRegister(payload: { opening_balance: number; register_id?: number }): Promise<CashRegisterSession> {
  const response = await apiClient.post<{ data: CashRegisterSession }>('/cash-registers/open', payload)
  return response.data.data
}

export async function closeCashRegister(payload: { actual_cash: number; closing_notes?: string; register_id?: number }): Promise<CashRegisterSession> {
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
