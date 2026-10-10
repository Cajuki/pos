import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CircleAlert, UserPlus, UsersRound } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { createStaff, getStaff } from '../api/commerce'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'

export default function StaffPage() {
  const queryClient = useQueryClient()
  const [isFormOpen, setIsFormOpen] = useState(false)
  const [invitedEmail, setInvitedEmail] = useState<string | null>(null)
  const staffQuery = useQuery({ queryKey: ['business-staff'], queryFn: getStaff })
  const createMutation = useMutation({
    mutationFn: createStaff,
    onSuccess: async (invitation) => {
      await queryClient.invalidateQueries({ queryKey: ['business-staff'] })
      setIsFormOpen(false)
      setInvitedEmail(invitation.email)
    },
  })

  function submitStaff(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const formData = new FormData(event.currentTarget)

    createMutation.mutate({
      name: String(formData.get('name')),
      email: String(formData.get('email')),
      role: String(formData.get('role')),
    })
  }

  return (
    <section className="module-page w-full">
      <div className="mb-6 flex w-full items-center justify-between gap-4">
        <div>
          <p className="eyebrow">Business access</p>
          <h1>Team</h1>
        </div>
        <Button variant="primary" onClick={() => setIsFormOpen((open) => !open)}>
          <UserPlus size={16} /> {isFormOpen ? 'Close form' : 'Add staff'}
        </Button>
      </div>

      {invitedEmail && <p role="status" className="w-full rounded-xl border border-success/20 bg-success/5 px-4 py-3 text-sm text-success">Invitation sent to {invitedEmail}. They can create their password from the email link.</p>}

      {isFormOpen && (
        <Card className="mb-5 w-full p-5">
          <form className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" onSubmit={submitStaff}>
            <label className="grid gap-1 text-sm font-medium text-graphite">Full name<input required name="name" maxLength={120} autoComplete="name" className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
            <label className="grid gap-1 text-sm font-medium text-graphite">Email<input required name="email" type="email" maxLength={255} autoComplete="email" className="rounded-xl border border-graphite/10 px-3 py-2.5" /></label>
            <label className="grid gap-1 text-sm font-medium text-graphite">Role<select name="role" defaultValue="cashier" className="rounded-xl border border-graphite/10 bg-white px-3 py-2.5"><option value="admin">Admin</option><option value="manager">Manager</option><option value="cashier">Cashier</option><option value="inventory">Inventory</option></select></label>
            <div className="flex flex-wrap items-center gap-3 sm:col-span-2 xl:col-span-3">
              <Button type="submit" disabled={createMutation.isPending}>{createMutation.isPending ? 'Sending invitation...' : 'Send invitation'}</Button>
              {createMutation.isError && <span role="alert" className="flex items-center gap-2 text-sm text-danger"><CircleAlert size={15} /> Could not send the invitation. Check the email and try again.</span>}
            </div>
          </form>
        </Card>
      )}

      <Card className="w-full p-5">
        <div className="mb-4 flex items-center gap-3">
          <UsersRound size={19} className="text-graphite/60" />
          <h2 className="text-base font-semibold text-graphite">Business members</h2>
          <span className="text-sm text-graphite/55">{staffQuery.data?.length ?? 0}</span>
        </div>
        {staffQuery.isError && <p role="alert" className="mb-4 text-sm text-danger">Team members could not be loaded.</p>}
        <div className="w-full overflow-x-auto">
          <table className="w-full min-w-[560px] text-left text-sm">
            <thead className="border-b border-graphite/10 text-xs uppercase text-graphite/55">
              <tr><th className="px-3 py-3 font-medium">Name</th><th className="px-3 py-3 font-medium">Email</th><th className="px-3 py-3 font-medium">Role</th><th className="px-3 py-3 font-medium">Status</th></tr>
            </thead>
            <tbody className="divide-y divide-graphite/10">
              {(staffQuery.data ?? []).map((member) => (
                <tr key={member.id}>
                  <td className="px-3 py-3 font-medium text-graphite">{member.name}</td>
                  <td className="px-3 py-3 text-graphite/70">{member.email}</td>
                  <td className="px-3 py-3 capitalize text-graphite/70">{member.role}</td>
                  <td className="px-3 py-3 capitalize text-graphite/70">{member.status}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {!staffQuery.isPending && !staffQuery.isError && !staffQuery.data?.length && <p className="py-8 text-center text-sm text-graphite/60">No members or pending invitations for this business.</p>}
        </div>
      </Card>
    </section>
  )
}