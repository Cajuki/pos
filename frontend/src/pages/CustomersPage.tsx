import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Search, UsersRound } from 'lucide-react'
import { useDeferredValue, useState, type FormEvent } from 'react'
import { createCustomer, getCustomers } from '../api/commerce'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'
import { DataTable } from '../components/ui/DataTable'

export default function CustomersPage() {
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [isCreateOpen, setIsCreateOpen] = useState(false)
  const deferredSearch = useDeferredValue(search)
  const customersQuery = useQuery({ queryKey: ['customers', deferredSearch], queryFn: () => getCustomers(deferredSearch) })
  const createMutation = useMutation({
    mutationFn: createCustomer,
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['customers'] })
      setIsCreateOpen(false)
    },
  })

  function submitCustomer(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const formData = new FormData(event.currentTarget)
    createMutation.mutate({
      name: String(formData.get('name')),
      email: String(formData.get('email')) || undefined,
      phone: String(formData.get('phone')) || undefined,
      address: String(formData.get('address')) || undefined,
    })
  }

  const rows = (customersQuery.data ?? []).map((customer) => ({
    ...customer,
    email: customer.email || '—',
    phone: customer.phone || '—',
    sales_count: customer.sales_count ?? 0,
  }))

  return (
    <section className="module-page">
      <div className="mb-3 flex w-full flex-wrap items-end justify-between gap-4">
        <div>
          <p className="eyebrow">Relationships</p>
          <h1>Customers</h1>
        </div>
        <Button variant="primary" icon={<Plus size={16} />} onClick={() => setIsCreateOpen((open) => !open)}>
          {isCreateOpen ? 'Close form' : 'Add customer'}
        </Button>
      </div>

      {isCreateOpen && <Card className="w-full p-5">
        <form className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" onSubmit={submitCustomer}>
          <label className="grid gap-1 text-sm font-medium text-graphite">Full name<input required name="name" maxLength={160} autoComplete="name" className="rounded-lg border border-graphite/15 px-3 py-2.5" /></label>
          <label className="grid gap-1 text-sm font-medium text-graphite">Phone<input name="phone" maxLength={40} autoComplete="tel" className="rounded-lg border border-graphite/15 px-3 py-2.5" /></label>
          <label className="grid gap-1 text-sm font-medium text-graphite">Email<input type="email" name="email" maxLength={255} autoComplete="email" className="rounded-lg border border-graphite/15 px-3 py-2.5" /></label>
          <label className="grid gap-1 text-sm font-medium text-graphite">Address<input name="address" maxLength={500} autoComplete="street-address" className="rounded-lg border border-graphite/15 px-3 py-2.5" /></label>
          <div className="flex items-center gap-3 sm:col-span-2 xl:col-span-4">
            <Button type="submit" disabled={createMutation.isPending}>{createMutation.isPending ? 'Saving...' : 'Save customer'}</Button>
            {createMutation.isError && <span role="alert" className="text-sm text-danger">Could not save this customer. Check the details and try again.</span>}
          </div>
        </form>
      </Card>}

      <Card className="w-full p-5">
        <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-2 text-sm font-semibold text-graphite"><UsersRound size={17} /> Customer directory</div>
          <label className="flex min-h-10 w-full items-center gap-2 rounded-lg border border-graphite/15 bg-white px-3 text-graphite/55 sm:max-w-xs">
            <Search size={16} /><input value={search} onChange={(event) => setSearch(event.target.value)} className="w-full bg-transparent text-sm text-graphite outline-none" placeholder="Search name, phone, email" aria-label="Search customers" />
          </label>
        </div>
        {customersQuery.isError && <p role="alert" className="mb-4 text-sm text-danger">Customers could not be loaded. Check the API connection and business context.</p>}
        <DataTable
          loading={customersQuery.isPending}
          rows={rows}
          emptyMessage={search ? 'No matching customers found.' : 'No customers yet. Add your first customer to build your directory.'}
          columns={[
            { header: 'Customer', accessor: 'name' },
            { header: 'Phone', accessor: 'phone' },
            { header: 'Email', accessor: 'email' },
            { header: 'Sales', accessor: 'sales_count' },
            { header: 'Status', accessor: 'status' },
          ]}
        />
      </Card>
    </section>
  )
}
