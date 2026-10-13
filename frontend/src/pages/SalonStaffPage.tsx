import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Search, UserPlus, UsersRound } from 'lucide-react'
import { useMemo, useState, type FormEvent } from 'react'
import { createSalonStaff, getBranches, getProducts, getSalonJobTitles, getSalonStaff, updateSalonStaffStatus } from '../api/commerce'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'

const staffPermissions = [
  ['appointments.manage', 'Manage appointments'], ['customers.read', 'View customers'], ['customers.manage', 'Manage customers'],
  ['products.read', 'View services and products'], ['sales.read', 'View sales'], ['sales.create', 'Process sales'],
  ['sales.refund', 'Process refunds'], ['inventory.read', 'View inventory'], ['inventory.manage', 'Manage inventory'],
  ['expenses.read', 'View expenses'], ['expenses.manage', 'Manage expenses'], ['reports.read', 'View reports'],
  ['commissions.read', 'View commissions'], ['commissions.manage', 'Manage commissions'],
]

export default function SalonStaffPage() {
  const client = useQueryClient()
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [formOpen, setFormOpen] = useState(false)
  const staffQuery = useQuery({ queryKey: ['salon-staff', search, status], queryFn: () => getSalonStaff({ search: search || undefined, status: status || undefined }) })
  const titlesQuery = useQuery({ queryKey: ['salon-job-titles'], queryFn: getSalonJobTitles })
  const branchesQuery = useQuery({ queryKey: ['branches'], queryFn: getBranches })
  const productsQuery = useQuery({ queryKey: ['products', 'active'], queryFn: () => getProducts('', true) })
  const services = useMemo(() => (productsQuery.data ?? []).filter((product) => product.product_type === 'service' && product.is_active), [productsQuery.data])
  const createMutation = useMutation({ mutationFn: createSalonStaff, onSuccess: async () => { await client.invalidateQueries({ queryKey: ['salon-staff'] }); setFormOpen(false) } })
  const statusMutation = useMutation({ mutationFn: ({ id, next }: { id: number; next: 'active' | 'inactive' }) => updateSalonStaffStatus(id, next), onSuccess: () => client.invalidateQueries({ queryKey: ['salon-staff'] }) })

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    createMutation.mutate({
      name: String(form.get('name')),
      phone: String(form.get('phone') ?? '') || undefined,
      email: String(form.get('email') ?? '') || undefined,
      employee_id: String(form.get('employee_id') ?? '') || undefined,
      employment_type: String(form.get('employment_type')),
      employment_started_on: String(form.get('employment_started_on') ?? '') || undefined,
      employment_status: 'active',
      job_title_ids: form.getAll('job_title_ids').map(Number),
      branch_ids: form.getAll('branch_ids').map(Number),
      service_ids: form.getAll('service_ids').map(Number),
      permission_overrides: form.getAll('permissions').map(String),
      commission_type: String(form.get('commission_type') ?? '') as 'percentage' | 'fixed' || undefined,
      commission_value: form.get('commission_value') ? Number(form.get('commission_value')) : undefined,
    })
  }

  return <section className="module-page w-full space-y-5">
    <div className="flex flex-wrap items-center justify-between gap-4">
      <div><p className="eyebrow">Salon operations</p><h1>Staff management</h1><p className="mt-1 text-sm text-graphite/60">Employee profiles and service assignments for this business.</p></div>
      <Button variant="primary" onClick={() => setFormOpen((open) => !open)}><UserPlus size={16} />{formOpen ? 'Close form' : 'Add staff'}</Button>
    </div>
    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
      {[['Total staff', staffQuery.data?.summary.total], ['Active', staffQuery.data?.summary.active], ['On duty', staffQuery.data?.summary.on_duty], ['On leave', staffQuery.data?.summary.on_leave]].map(([label, value]) => <Card key={String(label)} className="p-4"><p className="text-sm text-graphite/55">{label}</p><strong className="mt-1 block text-2xl">{staffQuery.isPending ? '—' : value}</strong></Card>)}
    </div>
    {formOpen && <Card className="p-5"><h2 className="mb-4 text-base font-semibold">New staff profile</h2><form onSubmit={submit} className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
      <label className="grid gap-1 text-sm">Full name<input required name="name" maxLength={160} className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
      <label className="grid gap-1 text-sm">Phone<input name="phone" type="tel" maxLength={40} className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
      <label className="grid gap-1 text-sm">Email (optional)<input name="email" type="email" className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
      <label className="grid gap-1 text-sm">Employee ID (optional)<input name="employee_id" maxLength={64} className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
      <label className="grid gap-1 text-sm">Employment type<select name="employment_type" className="rounded-xl border border-graphite/10 bg-white px-3 py-2.5"><option value="full_time">Full-time</option><option value="part_time">Part-time</option><option value="contract">Contract</option><option value="other">Other</option></select></label>
      <label className="grid gap-1 text-sm">Start date<input name="employment_started_on" type="date" className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
      <fieldset className="grid gap-2 sm:col-span-2"><legend className="text-sm font-medium">Professional job titles</legend><div className="flex flex-wrap gap-x-4 gap-y-2">{(titlesQuery.data ?? []).map((title) => <label key={title.id} className="flex items-center gap-2 text-sm"><input type="checkbox" name="job_title_ids" value={title.id} />{title.name}</label>)}</div></fieldset>
      <fieldset className="grid gap-2"><legend className="text-sm font-medium">Branches</legend>{(branchesQuery.data ?? []).filter((branch) => branch.is_active).map((branch) => <label key={branch.id} className="flex items-center gap-2 text-sm"><input type="checkbox" name="branch_ids" value={branch.id} />{branch.name}</label>)}</fieldset>
      <fieldset className="grid gap-2 sm:col-span-2"><legend className="text-sm font-medium">Qualified services</legend><div className="flex flex-wrap gap-x-4 gap-y-2">{services.map((service) => <label key={service.id} className="flex items-center gap-2 text-sm"><input type="checkbox" name="service_ids" value={service.id} />{service.name}</label>)}{!services.length && <span className="text-sm text-graphite/55">Add bookable services in Products first.</span>}</div></fieldset>
      <fieldset className="grid gap-2 sm:col-span-2 xl:col-span-3"><legend className="text-sm font-medium">POS permissions <span className="font-normal text-graphite/55">(independent of job title)</span></legend><div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">{staffPermissions.map(([key, label]) => <label key={key} className="flex items-center gap-2 text-sm"><input type="checkbox" name="permissions" value={key} />{label}</label>)}</div></fieldset>
      <div className="grid gap-3 sm:grid-cols-2 sm:col-span-2"><label className="grid gap-1 text-sm">Commission type<select name="commission_type" className="rounded-xl border border-graphite/10 bg-white px-3 py-2.5"><option value="">None</option><option value="percentage">Percentage</option><option value="fixed">Fixed per service</option></select></label><label className="grid gap-1 text-sm">Commission value<input name="commission_value" type="number" min="0" step="0.01" className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label></div>
      <div className="flex items-center gap-3 sm:col-span-2 xl:col-span-3"><Button type="submit" disabled={createMutation.isPending || titlesQuery.isPending || branchesQuery.isPending}>{createMutation.isPending ? 'Saving…' : 'Save staff profile'}</Button><span className="text-sm text-graphite/55">This creates a profile without requiring a POS login.</span></div>
      {createMutation.isError && <p role="alert" className="text-sm text-danger sm:col-span-2 xl:col-span-3">Could not save the profile. Check required job title and branch assignments, then try again.</p>}
    </form></Card>}
    <Card className="p-4 sm:p-5"><div className="mb-4 flex flex-wrap items-center justify-between gap-3"><div className="flex items-center gap-2"><UsersRound size={18} /><h2 className="font-semibold">Staff directory</h2></div><div className="flex gap-2"><label className="flex items-center gap-2 rounded-xl border border-graphite/10 px-3"><Search size={15} /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search staff" className="w-36 border-0 py-2 text-sm outline-none sm:w-52" /></label><select value={status} onChange={(event) => setStatus(event.target.value)} aria-label="Filter by employment status" className="rounded-xl border border-graphite/10 bg-white px-3 text-sm"><option value="">All status</option><option value="active">Active</option><option value="inactive">Inactive</option><option value="on_leave">On leave</option></select></div></div>
      {staffQuery.isError && <p role="alert" className="py-4 text-sm text-danger">Staff directory could not be loaded.</p>}
      <div className="overflow-x-auto"><table className="w-full min-w-[780px] text-left text-sm"><thead className="border-b border-graphite/10 text-xs uppercase text-graphite/55"><tr>{['Name', 'Job title', 'Branch', 'Services', 'Employment', 'Account', ''].map((heading) => <th key={heading} className="px-3 py-3 font-medium">{heading}</th>)}</tr></thead><tbody className="divide-y divide-graphite/10">{(staffQuery.data?.data ?? []).map((member) => <tr key={member.id}><td className="px-3 py-3"><strong>{member.name}</strong>{member.employee_id && <small className="block text-graphite/55">{member.employee_id}</small>}</td><td className="px-3 py-3">{member.job_titles.map((title) => title.name).join(', ')}</td><td className="px-3 py-3">{member.branches.map((branch) => branch.name).join(', ')}</td><td className="px-3 py-3">{member.services.map((service) => service.name).join(', ') || 'Not assigned'}</td><td className="px-3 py-3 capitalize">{member.employment_status.replace('_', ' ')}</td><td className="px-3 py-3">{member.account_status === 'profile_only' ? 'No login' : 'POS access'}</td><td className="px-3 py-3"><button type="button" disabled={statusMutation.isPending} onClick={() => statusMutation.mutate({ id: member.id, next: member.employment_status === 'active' ? 'inactive' : 'active' })} className="text-xs font-semibold text-graphite underline">{member.employment_status === 'active' ? 'Deactivate' : 'Reactivate'}</button></td></tr>)}</tbody></table></div>
      {!staffQuery.isPending && !staffQuery.data?.data.length && <p className="py-10 text-center text-sm text-graphite/55">No staff profiles match this filter.</p>}
    </Card>
  </section>
}
