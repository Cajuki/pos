import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { createAppointment, createSale, getAppointments, getBusinessProviders, getBusinessSettings, getCustomers, getProducts, updateAppointmentStatus, type Appointment } from '../api/commerce'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'

export default function AppointmentsPage() {
  const queryClient = useQueryClient()
  const [formError, setFormError] = useState('')
  const appointmentsQuery = useQuery({ queryKey: ['appointments'], queryFn: getAppointments })
  const productsQuery = useQuery({ queryKey: ['products', 'services'], queryFn: () => getProducts() })
  const providersQuery = useQuery({ queryKey: ['business-providers'], queryFn: getBusinessProviders })
  const customersQuery = useQuery({ queryKey: ['customers'], queryFn: () => getCustomers() })
  const settingsQuery = useQuery({ queryKey: ['business-settings'], queryFn: getBusinessSettings })
  const services = (productsQuery.data ?? []).filter((product) => product.product_type === 'service' && product.is_active)
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['appointments'] })
  const createMutation = useMutation({
    mutationFn: createAppointment,
    onSuccess: async () => {
      setFormError('')
      await refresh()
    },
    onError: () => setFormError('Appointment could not be booked. Check availability, service, staff, and time.'),
  })
  const statusMutation = useMutation({
    mutationFn: ({ id, status }: { id: number; status: Appointment['status'] }) => updateAppointmentStatus(id, status),
    onSuccess: refresh,
    onError: () => setFormError('Appointment status could not be updated.'),
  })
  const checkoutMutation = useMutation({
    mutationFn: (appointment: Appointment) => createSale({
      appointment_id: appointment.id,
      payment_method: paymentMethod,
      items: [{ product_id: appointment.service.id, quantity: 1, performed_by_user_id: appointment.staff.id }],
    }),
    onSuccess: async () => {
      setFormError('')
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['appointments'] }),
        queryClient.invalidateQueries({ queryKey: ['sales'] }),
        queryClient.invalidateQueries({ queryKey: ['cash-register'] }),
      ])
    },
    onError: () => setFormError('Service checkout failed. Check the open cash register and enabled payment methods.'),
  })
  const paymentOptions = settingsQuery.data?.settings.payment_methods ?? []
  const paymentMethod = paymentOptions.includes('cash') ? 'cash' : paymentOptions[0] ?? 'cash'

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const data = new FormData(event.currentTarget)
    const startsAt = String(data.get('starts_at') ?? '')
    const serviceProductId = Number(data.get('service_product_id'))
    const staffUserId = Number(data.get('staff_user_id'))
    if (!startsAt || !serviceProductId || !staffUserId) {
      setFormError('Choose a service, staff member, and start time.')
      return
    }
    createMutation.mutate({
      service_product_id: serviceProductId,
      staff_user_id: staffUserId,
      starts_at: new Date(startsAt).toISOString(),
      ...(data.get('customer_id') ? { customer_id: Number(data.get('customer_id')) } : {}),
      ...(data.get('notes') ? { notes: String(data.get('notes')) } : {}),
    })
  }

  return (
    <section className="module-page w-full">
      <div className="mb-6"><p className="eyebrow">Service business</p><h1>Appointments</h1><p className="text-sm text-graphite/60">Book a catalog service with a team member and track its progress.</p></div>
      <Card className="mb-5 p-5">
        <h2 className="mb-4 text-lg font-semibold">Book appointment</h2>
        {formError && <p role="alert" className="mb-3 rounded-xl bg-red-50 p-3 text-sm text-red-700">{formError}</p>}
        <form className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5" onSubmit={submit}>
          <label className="grid gap-1 text-sm">Service<select name="service_product_id" required defaultValue="" className="rounded-xl border border-graphite/10 bg-white px-3 py-2.5"><option value="" disabled>Select service</option>{services.map((service) => <option key={service.id} value={service.id}>{service.name}</option>)}</select></label>
          <label className="grid gap-1 text-sm">Staff member<select name="staff_user_id" required defaultValue="" className="rounded-xl border border-graphite/10 bg-white px-3 py-2.5"><option value="" disabled>Select staff</option>{(providersQuery.data ?? []).map((provider) => <option key={provider.id} value={provider.id}>{provider.name}</option>)}</select></label>
          <label className="grid gap-1 text-sm">Customer<select name="customer_id" defaultValue="" className="rounded-xl border border-graphite/10 bg-white px-3 py-2.5"><option value="">Walk-in / not set</option>{(customersQuery.data ?? []).map((customer) => <option key={customer.id} value={customer.id}>{customer.name}</option>)}</select></label>
          <label className="grid gap-1 text-sm">Starts at<input name="starts_at" type="datetime-local" required className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
          <label className="grid gap-1 text-sm">Notes<input name="notes" maxLength={2000} className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
          <div className="sm:col-span-2 xl:col-span-5"><Button variant="primary" type="submit" disabled={createMutation.isPending || !services.length || !providersQuery.data?.length}>{createMutation.isPending ? 'Booking...' : 'Book appointment'}</Button></div>
        </form>
        {!services.length && !productsQuery.isPending && <p className="mt-3 text-sm text-amber-700">Add a service product before booking appointments.</p>}
        {providersQuery.isError && <p role="alert" className="mt-3 text-sm text-red-700">Staff choices could not be loaded.</p>}
      </Card>
      {appointmentsQuery.isError && <p role="alert" className="mb-4 text-sm text-red-700">Appointments could not be loaded.</p>}
      <div className="grid gap-3">
        {(appointmentsQuery.data ?? []).map((appointment) => (
          <Card key={appointment.id} className="flex flex-wrap items-center justify-between gap-4 p-4">
            <div><strong>{appointment.service.name}</strong><p className="text-sm text-graphite/60">{new Date(appointment.starts_at).toLocaleString()} · {appointment.duration_minutes} min · {appointment.staff.name}{appointment.customer ? ` · ${appointment.customer.name}` : ''}</p></div>
            <div className="flex items-center gap-2"><span className="text-sm capitalize">{appointment.status.replace('_', ' ')}</span>{appointment.status === 'booked' && <><Button variant="secondary" onClick={() => statusMutation.mutate({ id: appointment.id, status: 'in_progress' })}>Start</Button><Button variant="secondary" onClick={() => statusMutation.mutate({ id: appointment.id, status: 'cancelled' })}>Cancel</Button></>}{appointment.status === 'in_progress' && <Button variant="secondary" onClick={() => statusMutation.mutate({ id: appointment.id, status: 'cancelled' })}>Cancel</Button>}{['booked', 'in_progress'].includes(appointment.status) && <Button variant="primary" disabled={checkoutMutation.isPending || settingsQuery.isPending || settingsQuery.isError || !paymentOptions.length} onClick={() => checkoutMutation.mutate(appointment)}>{checkoutMutation.isPending ? 'Charging...' : `Charge ${paymentMethod} & complete`}</Button>}</div>
          </Card>
        ))}
        {!appointmentsQuery.isPending && !appointmentsQuery.data?.length && <Card className="p-6 text-center text-sm text-graphite/60">No appointments booked.</Card>}
      </div>
    </section>
  )
}
