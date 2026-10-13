import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { checkInAppointment, createAppointment, createSale, getAppointments, getBranches, getBusinessProviders, getBusinessSettings, getCustomers, getProducts, getStaffSchedules, saveStaffSchedule, updateAppointment, updateAppointmentStatus, type Appointment } from '../api/commerce'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'

function localDateTime(value: string): string {
  const date = new Date(value)
  return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16)
}

export default function AppointmentsPage() {
  const queryClient = useQueryClient()
  const [formError, setFormError] = useState('')
  const [selectedDate, setSelectedDate] = useState(() => new Date().toLocaleDateString('en-CA'))
  const [viewMode, setViewMode] = useState<'day' | 'week' | 'staff'>('day')
  const [selectedStaff, setSelectedStaff] = useState('')
  const [selectedBranch, setSelectedBranch] = useState('')
  const [scheduleStaffId, setScheduleStaffId] = useState('')
  const [scheduleBranchId, setScheduleBranchId] = useState('')
  const [scheduleDay, setScheduleDay] = useState(1)
  const [editing, setEditing] = useState<Appointment | null>(null)
  const [walkIn, setWalkIn] = useState(false)
  const branchesQuery = useQuery({ queryKey: ['branches'], queryFn: getBranches })
  const providersQuery = useQuery({ queryKey: ['business-providers'], queryFn: getBusinessProviders })
  const accountProviders = (providersQuery.data ?? []).filter((provider) => provider.staff_user_id !== null)
  const productsQuery = useQuery({ queryKey: ['products', 'services'], queryFn: () => getProducts() })
  const customersQuery = useQuery({ queryKey: ['customers'], queryFn: () => getCustomers() })
  const settingsQuery = useQuery({ queryKey: ['business-settings'], queryFn: getBusinessSettings })
  const effectiveScheduleStaffId = scheduleStaffId || String(accountProviders[0]?.staff_user_id ?? '')
  const effectiveScheduleBranchId = scheduleBranchId || String(branchesQuery.data?.find((branch) => branch.is_default)?.id ?? branchesQuery.data?.[0]?.id ?? '')
  const schedulesQuery = useQuery({
    queryKey: ['staff-schedules', effectiveScheduleBranchId, effectiveScheduleStaffId],
    queryFn: () => getStaffSchedules({ branch_id: Number(effectiveScheduleBranchId), staff_user_id: Number(effectiveScheduleStaffId) }),
    enabled: !!effectiveScheduleBranchId && !!effectiveScheduleStaffId,
  })
  const services = (productsQuery.data ?? []).filter((product) => product.product_type === 'service' && product.is_active)

  const scheduleStart = new Date(`${selectedDate}T00:00:00`)
  if (viewMode === 'week') scheduleStart.setDate(scheduleStart.getDate() - ((scheduleStart.getDay() + 6) % 7))
  const scheduleEnd = new Date(scheduleStart)
  scheduleEnd.setDate(scheduleEnd.getDate() + (viewMode === 'week' ? 7 : 1))
  const appointmentFilters = {
    from: scheduleStart.toISOString(),
    to: scheduleEnd.toISOString(),
    ...(selectedStaff.startsWith('profile-') ? { staff_profile_id: Number(selectedStaff.slice(8)) } : selectedStaff.startsWith('user-') ? { staff_user_id: Number(selectedStaff.slice(5)) } : {}),
    ...(selectedBranch ? { branch_id: Number(selectedBranch) } : {}),
  }
  const appointmentsQuery = useQuery({ queryKey: ['appointments', appointmentFilters], queryFn: () => getAppointments(appointmentFilters) })
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['appointments'] })

  const createMutation = useMutation({
    mutationFn: createAppointment,
    onSuccess: async () => { setFormError(''); setEditing(null); setWalkIn(false); await refresh() },
    onError: () => setFormError('Appointment could not be booked. Check branch hours, service, staff, and time.'),
  })
  const updateMutation = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: Parameters<typeof updateAppointment>[1] }) => updateAppointment(id, payload),
    onSuccess: async () => { setFormError(''); setEditing(null); await refresh() },
    onError: () => setFormError('Appointment could not be rescheduled. Check staff availability and branch hours.'),
  })
  const statusMutation = useMutation({
    mutationFn: ({ id, status }: { id: number; status: Appointment['status'] }) => updateAppointmentStatus(id, status),
    onSuccess: refresh,
    onError: () => setFormError('Appointment status could not be updated.'),
  })
  const checkInMutation = useMutation({ mutationFn: checkInAppointment, onSuccess: refresh, onError: () => setFormError('Could not check in this appointment.') })
  const scheduleMutation = useMutation({
    mutationFn: saveStaffSchedule,
    onSuccess: async () => { setFormError(''); await queryClient.invalidateQueries({ queryKey: ['staff-schedules'] }) },
    onError: () => setFormError('Working hours could not be saved. Check the branch, staff member, and break times.'),
  })
  const paymentOptions = settingsQuery.data?.settings.payment_methods ?? []
  const paymentMethod = paymentOptions.includes('cash') ? 'cash' : paymentOptions[0] ?? 'cash'
  const checkoutMutation = useMutation({
    mutationFn: (appointment: Appointment) => createSale({
      appointment_id: appointment.id,
      payment_method: paymentMethod,
      items: [{ product_id: appointment.service.id, quantity: 1, ...(appointment.staff ? { performed_by_user_id: appointment.staff.id } : {}) }],
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

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const data = new FormData(event.currentTarget)
    const startsAt = String(data.get('starts_at') ?? '')
    const serviceProductId = Number(data.get('service_product_id'))
    const staffAssignment = String(data.get('staff_assignment') ?? '')
    const staffProvider = providersQuery.data?.find((provider) => provider.id === staffAssignment)
    if ((!startsAt && !walkIn && !editing) || !serviceProductId || !staffProvider) {
      setFormError('Choose a service and staff member, then set a time or mark the booking as a walk-in.')
      return
    }
    const common = {
      service_product_id: serviceProductId,
      ...(staffProvider.staff_user_id ? { staff_user_id: staffProvider.staff_user_id } : { staff_profile_id: staffProvider.staff_profile_id! }),
      ...(selectedBranch ? { branch_id: Number(selectedBranch) } : {}),
      ...(data.get('customer_id') ? { customer_id: Number(data.get('customer_id')) } : {}),
      ...(data.get('notes') ? { notes: String(data.get('notes')) } : {}),
    }
    if (editing) {
      updateMutation.mutate({
        id: editing.id,
        payload: { ...common, starts_at: new Date(startsAt).toISOString() },
      })
    } else {
      createMutation.mutate({
        ...common,
        ...(!walkIn && startsAt ? { starts_at: new Date(startsAt).toISOString() } : {}),
        ...(walkIn ? { walk_in: true } : {}),
      })
    }
  }

  function beginReschedule(appointment: Appointment) {
    setEditing(appointment)
    const provider = appointment.staff_profile_id ? `profile-${appointment.staff_profile_id}` : `user-${appointment.staff_user_id}`
    setSelectedStaff(provider)
    setSelectedBranch(appointment.branch_id ? String(appointment.branch_id) : '')
    setWalkIn(false)
    setFormError('')
  }

  function submitSchedule(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const data = new FormData(event.currentTarget)
    scheduleMutation.mutate({
      branch_id: Number(effectiveScheduleBranchId),
      staff_user_id: Number(effectiveScheduleStaffId),
      day_of_week: Number(data.get('day_of_week')),
      is_working: data.get('is_working') === 'on',
      starts_at: String(data.get('starts_at') || '00:00'),
      ends_at: String(data.get('ends_at') || '00:00'),
      break_starts_at: String(data.get('break_starts_at') || '') || null,
      break_ends_at: String(data.get('break_ends_at') || '') || null,
    })
  }
  const selectedSchedule = (schedulesQuery.data ?? []).find((schedule) => schedule.day_of_week === scheduleDay)

  return (
    <section className="module-page appointments-page w-full">
      <div className="appointments-header mb-6"><div><p className="eyebrow">Service business</p><h1>Appointments</h1><p className="text-sm text-graphite/60">Manage bookings, walk-ins, staff availability, and check-in.</p></div><div className="appointment-header-count"><span className="appointment-live-dot" />{appointmentsQuery.data?.length ?? 0}<small>appointments in this view</small></div></div>
      <Card className="appointment-booking-card mb-5 p-5">
        <div className="appointment-section-heading"><div><p className="eyebrow">Booking desk</p><h2>{editing ? 'Reschedule appointment' : 'Book appointment or walk-in'}</h2></div><span className="appointment-step-badge">01</span></div>
        {formError && <p role="alert" className="mb-3 rounded-xl bg-red-50 p-3 text-sm text-red-700">{formError}</p>}
        <form key={editing?.id ?? 'new-appointment'} className="appointment-form grid gap-3 sm:grid-cols-2 xl:grid-cols-5" onSubmit={submit}>
          <label className="grid gap-1 text-sm">Service<select name="service_product_id" required defaultValue={editing?.service.id ?? ''} className="rounded-xl border border-graphite/10 bg-white px-3 py-2.5"><option value="" disabled>Select service</option>{services.map((service) => <option key={service.id} value={service.id}>{service.name} · {service.service_duration_minutes ?? 30} min</option>)}</select></label>
          <label className="grid gap-1 text-sm">Staff member<select name="staff_assignment" required defaultValue={editing ? (editing.staff_profile_id ? `profile-${editing.staff_profile_id}` : `user-${editing.staff_user_id}`) : ''} className="rounded-xl border border-graphite/10 bg-white px-3 py-2.5"><option value="" disabled>Select staff</option>{(providersQuery.data ?? []).map((provider) => <option key={provider.id} value={provider.id}>{provider.name}</option>)}</select></label>
          {(branchesQuery.data?.length ?? 0) > 1 && <label className="grid gap-1 text-sm">Branch<select required value={selectedBranch || String(editing?.branch_id ?? '')} onChange={(event) => setSelectedBranch(event.target.value)} className="rounded-xl border border-graphite/10 bg-white px-3 py-2.5"><option value="">Choose branch</option>{(branchesQuery.data ?? []).map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></label>}
          <label className="grid gap-1 text-sm">Customer<select name="customer_id" defaultValue={editing?.customer?.id ?? ''} className="rounded-xl border border-graphite/10 bg-white px-3 py-2.5"><option value="">Walk-in / not set</option>{(customersQuery.data ?? []).map((customer) => <option key={customer.id} value={customer.id}>{customer.name}</option>)}</select></label>
          <label className="grid gap-1 text-sm">Starts at<input name="starts_at" type="datetime-local" required={!walkIn || !!editing} disabled={walkIn && !editing} defaultValue={editing ? localDateTime(editing.starts_at) : ''} className="rounded-xl border border-graphite/10 px-3 py-2.5 disabled:opacity-50" /></label>
          {!editing && <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={walkIn} onChange={(event) => setWalkIn(event.target.checked)} className="h-4 w-4 accent-lime-dark" />Walk-in (check in now)</label>}
          <label className="grid gap-1 text-sm">Notes<input name="notes" defaultValue={editing?.notes ?? ''} maxLength={2000} className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
          <div className="flex gap-2 sm:col-span-2 xl:col-span-5"><Button variant="primary" type="submit" disabled={createMutation.isPending || updateMutation.isPending || !services.length || !providersQuery.data?.length}>{editing ? 'Save reschedule' : 'Book appointment'}</Button>{editing && <Button variant="secondary" type="button" onClick={() => setEditing(null)}>Close</Button>}</div>
        </form>
        {!services.length && !productsQuery.isPending && <p className="mt-3 text-sm text-amber-700">Add a service product before booking appointments.</p>}
        {providersQuery.isError && <p role="alert" className="mt-3 text-sm text-red-700">Staff choices could not be loaded.</p>}
        {branchesQuery.isError && <p role="alert" className="mt-3 text-sm text-red-700">Branch choices could not be loaded. Ask a manager to check your branch access.</p>}
      </Card>
      <Card className="appointment-filters mb-4 flex flex-wrap items-end gap-3 p-4">
        <label className="grid gap-1 text-sm">View<select value={viewMode} onChange={(event) => setViewMode(event.target.value as 'day' | 'week' | 'staff')} className="rounded-xl border border-graphite/10 bg-white px-3 py-2"><option value="day">Day</option><option value="week">Week</option><option value="staff">Staff</option></select></label>
        <label className="grid gap-1 text-sm">Date<input type="date" value={selectedDate} onChange={(event) => setSelectedDate(event.target.value)} className="rounded-xl border border-graphite/10 px-3 py-2" /></label>
        {(viewMode === 'staff' || viewMode === 'week') && <label className="grid gap-1 text-sm">Staff<select value={selectedStaff} onChange={(event) => setSelectedStaff(event.target.value)} className="rounded-xl border border-graphite/10 bg-white px-3 py-2"><option value="">All staff</option>{(providersQuery.data ?? []).map((provider) => <option key={provider.id} value={provider.id}>{provider.name}</option>)}</select></label>}
        {(branchesQuery.data?.length ?? 0) > 1 && <label className="grid gap-1 text-sm">Branch<select value={selectedBranch} onChange={(event) => setSelectedBranch(event.target.value)} className="rounded-xl border border-graphite/10 bg-white px-3 py-2"><option value="">All branches</option>{(branchesQuery.data ?? []).map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></label>}
      </Card>
      {effectiveScheduleBranchId && effectiveScheduleStaffId && <Card className="appointment-schedule mb-4 p-5">
        <h2 className="mb-3 text-lg font-semibold">Weekly staff hours and breaks</h2>
        <form key={`${effectiveScheduleBranchId}-${effectiveScheduleStaffId}-${scheduleDay}`} className="grid gap-3 sm:grid-cols-2 xl:grid-cols-6" onSubmit={submitSchedule}>
          <label className="grid gap-1 text-sm">Staff<select value={effectiveScheduleStaffId} onChange={(event) => setScheduleStaffId(event.target.value)} className="rounded-xl border border-graphite/10 bg-white px-3 py-2"><option value="">Select staff</option>{accountProviders.map((provider) => <option key={provider.id} value={provider.staff_user_id!}>{provider.name}</option>)}</select></label>
          <label className="grid gap-1 text-sm">Branch<select value={effectiveScheduleBranchId} onChange={(event) => setScheduleBranchId(event.target.value)} className="rounded-xl border border-graphite/10 bg-white px-3 py-2"><option value="">Select branch</option>{(branchesQuery.data ?? []).map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></label>
          <label className="grid gap-1 text-sm">Day<select value={scheduleDay} onChange={(event) => setScheduleDay(Number(event.target.value))} className="rounded-xl border border-graphite/10 bg-white px-3 py-2">{['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'].map((day, index) => <option key={day} value={index}>{day}</option>)}</select></label>
          <label className="grid gap-1 text-sm">Starts<input name="starts_at" type="time" defaultValue={selectedSchedule?.starts_at?.slice(0, 5) ?? '09:00'} className="rounded-xl border border-graphite/10 px-3 py-2" /></label>
          <label className="grid gap-1 text-sm">Ends<input name="ends_at" type="time" defaultValue={selectedSchedule?.ends_at?.slice(0, 5) ?? '17:00'} className="rounded-xl border border-graphite/10 px-3 py-2" /></label>
          <div className="flex items-center gap-2"><label className="grid gap-1 text-sm">Break from<input name="break_starts_at" type="time" defaultValue={selectedSchedule?.break_starts_at?.slice(0, 5) ?? ''} className="rounded-xl border border-graphite/10 px-3 py-2" /></label><label className="grid gap-1 text-sm">Break to<input name="break_ends_at" type="time" defaultValue={selectedSchedule?.break_ends_at?.slice(0, 5) ?? ''} className="rounded-xl border border-graphite/10 px-3 py-2" /></label></div>
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" name="is_working" defaultChecked={selectedSchedule?.is_working ?? true} className="h-4 w-4 accent-lime-dark" />Working this day</label>
          <div><Button variant="secondary" type="submit" disabled={scheduleMutation.isPending}>{scheduleMutation.isPending ? 'Saving…' : 'Save hours'}</Button></div>
        </form>
      </Card>}
      {appointmentsQuery.isError && <p role="alert" className="mb-4 text-sm text-red-700">Appointments could not be loaded.</p>}
      <div className="appointment-list grid gap-3">
        {(appointmentsQuery.data ?? []).map((appointment) => (
          <Card key={appointment.id} className="appointment-row flex flex-wrap items-center justify-between gap-4 p-4">
            <div><strong>{appointment.service.name}</strong><p className="text-sm text-graphite/60">{new Date(appointment.starts_at).toLocaleString()} · {appointment.duration_minutes} min{appointment.buffer_minutes ? ` + ${appointment.buffer_minutes} min buffer` : ''} · {appointment.staff?.name ?? appointment.staff_profile?.name ?? 'Staff'}{appointment.customer ? ` · ${appointment.customer.name}` : ''}{appointment.booking_source === 'walk_in' ? ' · Walk-in' : ''}</p></div>
            <div className="flex flex-wrap items-center gap-2"><span className="text-sm capitalize">{appointment.checked_in_at && appointment.status === 'booked' ? 'Waiting' : appointment.status.replace('_', ' ')}</span>{appointment.status === 'booked' && <><Button variant="secondary" onClick={() => checkInMutation.mutate(appointment.id)} disabled={!!appointment.checked_in_at}>{appointment.checked_in_at ? 'Checked in' : 'Check in'}</Button><Button variant="secondary" onClick={() => statusMutation.mutate({ id: appointment.id, status: 'in_progress' })}>Start</Button><Button variant="secondary" onClick={() => beginReschedule(appointment)}>Reschedule</Button><Button variant="secondary" onClick={() => statusMutation.mutate({ id: appointment.id, status: 'no_show' })}>No-show</Button><Button variant="secondary" onClick={() => statusMutation.mutate({ id: appointment.id, status: 'cancelled' })}>Cancel</Button></>}{appointment.status === 'in_progress' && <Button variant="secondary" onClick={() => statusMutation.mutate({ id: appointment.id, status: 'cancelled' })}>Cancel</Button>}{['booked', 'in_progress'].includes(appointment.status) && <Button variant="primary" disabled={checkoutMutation.isPending || settingsQuery.isPending || settingsQuery.isError || !paymentOptions.length} onClick={() => checkoutMutation.mutate(appointment)}>{checkoutMutation.isPending ? 'Charging...' : `Charge ${paymentMethod} & complete`}</Button>}</div>
          </Card>
        ))}
        {appointmentsQuery.isPending && <Card className="p-6 text-center text-sm text-graphite/60">Loading appointments…</Card>}
        {!appointmentsQuery.isPending && !appointmentsQuery.isError && !appointmentsQuery.data?.length && <Card className="p-6 text-center text-sm text-graphite/60">No appointments for this view.</Card>}
      </div>
    </section>
  )
}
