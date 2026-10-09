import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { BadgeCheck, Building2, CircleAlert, CreditCard, FileText, SlidersHorizontal } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { getBusinessSettings, updateBusinessSettings, type BusinessSettings } from '../api/commerce'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'

const tabs = [
  { id: 'business', label: 'Business', icon: Building2 },
  { id: 'checkout', label: 'Checkout & tax', icon: SlidersHorizontal },
  { id: 'payments', label: 'Payments', icon: CreditCard },
  { id: 'receipt', label: 'Receipt', icon: FileText },
] as const

type SettingsTab = typeof tabs[number]['id']

const paymentOptions = [
  { value: 'cash', label: 'Cash', detail: 'Physical cash and register reconciliation' },
  { value: 'mpesa', label: 'M-Pesa', detail: 'Mobile money payments' },
  { value: 'card', label: 'Card', detail: 'Debit and credit card payments' },
  { value: 'bank', label: 'Bank transfer', detail: 'Direct bank payments' },
  { value: 'credit', label: 'Store credit', detail: 'Record a customer credit sale' },
] as const

const currencies = [
  ['KES', 'Kenyan Shilling'],
  ['UGX', 'Ugandan Shilling'],
  ['TZS', 'Tanzanian Shilling'],
  ['RWF', 'Rwandan Franc'],
  ['USD', 'US Dollar'],
  ['EUR', 'Euro'],
  ['GBP', 'British Pound'],
  ['ZAR', 'South African Rand'],
] as const

const fieldClass = 'mt-1 w-full rounded-lg border border-graphite/15 bg-white px-3 py-2.5 text-sm text-graphite'
const labelClass = 'grid gap-1 text-sm font-medium text-graphite'

export default function SettingsPage() {
  const settingsQuery = useQuery({ queryKey: ['business-settings'], queryFn: getBusinessSettings })

  return (
    <section className="module-page">
      <div className="mb-6">
        <p className="eyebrow">BUSINESS OPERATIONS</p>
        <h1>Settings</h1>
        <p className="mt-1 text-sm text-graphite/60">Configure how your business runs at the point of sale.</p>
      </div>
      {settingsQuery.isPending && <Card className="p-6 text-sm text-graphite/60">Loading business settings...</Card>}
      {settingsQuery.isError && <div role="alert" className="mb-4 flex items-center gap-2 text-sm text-danger"><CircleAlert size={16} /> Settings could not be loaded. Check the API connection and try again.</div>}
      {settingsQuery.data && <SettingsEditor key={settingsQuery.data.id} initialSettings={settingsQuery.data} />}
    </section>
  )
}

function SettingsEditor({ initialSettings }: { initialSettings: BusinessSettings }) {
  const queryClient = useQueryClient()
  const [activeTab, setActiveTab] = useState<SettingsTab>('business')
  const [draft, setDraft] = useState(initialSettings)
  const saveMutation = useMutation({
    mutationFn: updateBusinessSettings,
    onSuccess: async (settings) => {
      setDraft(settings)
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['business-settings'] }),
        queryClient.invalidateQueries({ queryKey: ['current-business'] }),
      ])
    },
  })

  function submitSettings(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    saveMutation.mutate(draft)
  }

  function updateDraft(update: (current: BusinessSettings) => BusinessSettings) {
    setDraft(update)
    saveMutation.reset()
  }

  function togglePayment(method: BusinessSettings['settings']['payment_methods'][number]) {
    updateDraft((current) => {
      const enabled = current.settings.payment_methods.includes(method)
      if (enabled && current.settings.payment_methods.length === 1) return current

      const payment_methods = enabled
        ? current.settings.payment_methods.filter((item) => item !== method)
        : [...current.settings.payment_methods, method]

      return {
        ...current,
        settings: {
          ...current.settings,
          payment_methods,
          default_payment_method: enabled && current.settings.default_payment_method === method
            ? payment_methods[0]
            : current.settings.default_payment_method,
        },
      }
    })
  }

  return (
    <>
      <div className="mb-5 flex justify-end">
        <Button type="submit" form="business-settings-form" disabled={saveMutation.isPending}>
          {saveMutation.isPending ? 'Saving changes...' : 'Save changes'}
        </Button>
      </div>

        <form id="business-settings-form" onSubmit={submitSettings} className="grid items-start gap-5 xl:grid-cols-[220px_minmax(0,1fr)]">
          <Card className="p-2">
            <nav aria-label="Settings sections" className="grid gap-1">
              {tabs.map(({ id, label, icon: Icon }) => (
                <button
                  key={id}
                  type="button"
                  onClick={() => setActiveTab(id)}
                  aria-current={activeTab === id ? 'page' : undefined}
                  className={`flex items-center gap-3 rounded-lg px-3 py-3 text-left text-sm font-medium transition-colors ${activeTab === id ? 'bg-graphite text-white' : 'text-graphite/70 hover:bg-graphite/5'}`}
                >
                  <Icon size={17} />{label}
                </button>
              ))}
            </nav>
          </Card>

          <div className="grid gap-5">
            {activeTab === 'business' && (
              <Card className="p-5 sm:p-6">
                <SectionHeading title="Business profile" description="Business details are used across your workspace and customer receipts." />
                <div className="mt-5 grid gap-4 sm:grid-cols-2">
                  <label className={labelClass}>Business name<input required maxLength={160} className={fieldClass} value={draft.name} onChange={(event) => updateDraft((current) => ({ ...current, name: event.target.value }))} /></label>
                  <label className={labelClass}>Currency<select className={fieldClass} value={draft.currency} onChange={(event) => updateDraft((current) => ({ ...current, currency: event.target.value }))}>{currencies.map(([code, label]) => <option key={code} value={code}>{code} · {label}</option>)}</select></label>
                  <label className={labelClass}>Time zone<select className={fieldClass} value={draft.timezone} onChange={(event) => updateDraft((current) => ({ ...current, timezone: event.target.value }))}>
                    {['Africa/Nairobi', 'Africa/Kampala', 'Africa/Dar_es_Salaam', 'Africa/Kigali', 'Africa/Johannesburg', 'UTC', 'Europe/London', 'America/New_York'].map((zone) => <option key={zone} value={zone}>{zone.replaceAll('_', ' ')}</option>)}
                  </select></label>
                  <label className={labelClass}>Business phone<input maxLength={40} type="tel" className={fieldClass} value={draft.settings.business_phone} onChange={(event) => updateDraft((current) => ({ ...current, settings: { ...current.settings, business_phone: event.target.value } }))} placeholder="+254 700 000 000" /></label>
                  <label className={labelClass}>Business email<input maxLength={255} type="email" className={fieldClass} value={draft.settings.business_email} onChange={(event) => updateDraft((current) => ({ ...current, settings: { ...current.settings, business_email: event.target.value } }))} placeholder="hello@yourbusiness.com" /></label>
                  <label className={labelClass}>Tax identification number<input maxLength={80} className={fieldClass} value={draft.settings.tax_number} onChange={(event) => updateDraft((current) => ({ ...current, settings: { ...current.settings, tax_number: event.target.value } }))} placeholder="Optional" /></label>
                  <label className={`${labelClass} sm:col-span-2`}>Business address<textarea rows={2} maxLength={255} className={fieldClass} value={draft.settings.business_address} onChange={(event) => updateDraft((current) => ({ ...current, settings: { ...current.settings, business_address: event.target.value } }))} placeholder="Street, city, country" /></label>
                </div>
              </Card>
            )}

            {activeTab === 'checkout' && (
              <Card className="p-5 sm:p-6">
                <SectionHeading title="Checkout & tax" description="Set how tax is calculated and presented on every sale." />
                <ToggleRow
                  title="Collect sales tax"
                  description="Apply the configured tax rate to new sales."
                  checked={draft.settings.tax_enabled}
                  onChange={(checked) => updateDraft((current) => ({ ...current, settings: { ...current.settings, tax_enabled: checked } }))}
                />
                <div className="mt-4 grid gap-4 sm:grid-cols-2">
                  <label className={labelClass}>Tax rate (%)<input type="number" min="0" max="100" step="0.01" required={draft.settings.tax_enabled} disabled={!draft.settings.tax_enabled} className={fieldClass} value={draft.settings.tax_rate} onChange={(event) => updateDraft((current) => ({ ...current, settings: { ...current.settings, tax_rate: event.target.value } }))} /></label>
                </div>
                <div className="mt-4 rounded-xl border border-graphite/10 px-4">
                  <ToggleRow
                    title="Prices already include tax"
                    description="When enabled, tax is included in the displayed item prices instead of added at checkout."
                    checked={draft.settings.tax_inclusive}
                    disabled={!draft.settings.tax_enabled}
                    onChange={(checked) => updateDraft((current) => ({ ...current, settings: { ...current.settings, tax_inclusive: checked } }))}
                  />
                </div>
                <div className="mt-5 border-t border-graphite/10 pt-2">
                  <ToggleRow
                    title="Allow checkout discounts"
                    description="Let cashiers apply a percentage discount up to the maximum set below."
                    checked={draft.settings.discount_enabled}
                    onChange={(checked) => updateDraft((current) => ({
                      ...current,
                      settings: {
                        ...current.settings,
                        discount_enabled: checked,
                        max_discount_percent: checked ? current.settings.max_discount_percent : '0.00',
                      },
                    }))}
                  />
                  <label className={labelClass}>Maximum discount (%)<input type="number" min="0.01" max="100" step="0.01" required={draft.settings.discount_enabled} disabled={!draft.settings.discount_enabled} className={fieldClass} value={draft.settings.max_discount_percent} onChange={(event) => updateDraft((current) => ({ ...current, settings: { ...current.settings, max_discount_percent: event.target.value } }))} /></label>
                </div>
              </Card>
            )}

            {activeTab === 'payments' && (
              <Card className="p-5 sm:p-6">
                <SectionHeading title="Payment methods" description="Only enabled methods appear at checkout. At least one method must remain available." />
                <div className="mt-5 divide-y divide-graphite/10">
                  {paymentOptions.map(({ value, label, detail }) => (
                    <label key={value} className="flex cursor-pointer items-center gap-3 py-4">
                      <input type="checkbox" checked={draft.settings.payment_methods.includes(value)} disabled={draft.settings.payment_methods.length === 1 && draft.settings.payment_methods.includes(value)} onChange={() => togglePayment(value)} className="h-4 w-4 accent-lime-dark" />
                      <span className="grid flex-1 gap-1"><strong className="text-sm font-semibold">{label}</strong><small className="text-xs text-graphite/55">{detail}</small></span>
                      {draft.settings.default_payment_method === value && <span className="rounded-full bg-lime/30 px-2.5 py-1 text-xs font-semibold text-graphite">Default</span>}
                    </label>
                  ))}
                </div>
                <label className={`${labelClass} mt-4 max-w-sm`}>Default payment method<select className={fieldClass} value={draft.settings.default_payment_method} onChange={(event) => updateDraft((current) => ({ ...current, settings: { ...current.settings, default_payment_method: paymentOptions.find(({ value }) => value === event.target.value)?.value ?? current.settings.default_payment_method } }))}>
                  {paymentOptions.filter(({ value }) => draft.settings.payment_methods.includes(value)).map(({ value, label }) => <option key={value} value={value}>{label}</option>)}
                </select></label>
              </Card>
            )}

            {activeTab === 'receipt' && (
              <Card className="p-5 sm:p-6">
                <SectionHeading title="Receipt details" description="Make printed receipts recognizable and easy for customers to follow up." />
                <ToggleRow
                  title="Show business contact details"
                  description="Print the business address, phone, and email when provided."
                  checked={draft.settings.receipt_show_business_details}
                  onChange={(checked) => updateDraft((current) => ({ ...current, settings: { ...current.settings, receipt_show_business_details: checked } }))}
                />
                <label className={`${labelClass} mt-4`}>Receipt footer<textarea rows={3} maxLength={250} className={fieldClass} value={draft.settings.receipt_footer} onChange={(event) => updateDraft((current) => ({ ...current, settings: { ...current.settings, receipt_footer: event.target.value } }))} placeholder="Thank you for shopping with us." /></label>
                <div className="mt-4 rounded-lg bg-champagne-light p-4 text-sm text-graphite/70">
                  <strong className="block text-graphite">{draft.name}</strong>
                  {draft.settings.receipt_show_business_details && <span>{[draft.settings.business_address, draft.settings.business_phone, draft.settings.business_email].filter(Boolean).join(' · ') || 'Add contact details in Business settings'}</span>}
                  <p className="mb-0 mt-2">{draft.settings.receipt_footer || 'No footer message'}</p>
                </div>
              </Card>
            )}

            <div className="flex min-h-10 items-center justify-end gap-3">
              {saveMutation.isError && <p role="alert" className="mr-auto flex items-center gap-2 text-sm text-danger"><CircleAlert size={16} /> Settings could not be saved. Review the values and try again.</p>}
              {saveMutation.isSuccess && <p role="status" className="mr-auto flex items-center gap-2 text-sm text-success"><BadgeCheck size={16} /> Settings saved.</p>}
            </div>
          </div>
        </form>
    </>
  )
}

function SectionHeading({ title, description }: { title: string; description: string }) {
  return <div className="border-b border-graphite/10 pb-4"><h2 className="text-lg font-bold text-graphite">{title}</h2><p className="mt-1 text-sm text-graphite/60">{description}</p></div>
}

function ToggleRow({ title, description, checked, disabled = false, onChange }: { title: string; description: string; checked: boolean; disabled?: boolean; onChange: (checked: boolean) => void }) {
  return (
    <label className={`flex items-center justify-between gap-4 py-4 ${disabled ? 'opacity-50' : 'cursor-pointer'}`}>
      <span className="grid gap-1"><strong className="text-sm font-semibold text-graphite">{title}</strong><small className="text-xs text-graphite/55">{description}</small></span>
      <input type="checkbox" checked={checked} disabled={disabled} onChange={(event) => onChange(event.target.checked)} className="h-4 w-4 shrink-0 accent-lime-dark" />
    </label>
  )
}
