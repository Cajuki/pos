import { useMutation, useQuery } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { acceptTeamInvitation, getTeamInvitation } from '../../api/auth'
import { saveAuthSession } from '../../api/auth'
import { Button } from '../../components/ui/Button'

export default function AcceptInvitationPage() {
  const [searchParams] = useSearchParams()
  const navigate = useNavigate()
  const token = searchParams.get('token') ?? ''
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const invitationQuery = useQuery({
    queryKey: ['team-invitation', token],
    queryFn: () => getTeamInvitation(token),
    enabled: Boolean(token),
    retry: false,
  })
  const acceptMutation = useMutation({
    mutationFn: () => acceptTeamInvitation(token, {
      password,
      password_confirmation: passwordConfirmation,
    }),
    onSuccess: (session) => {
      saveAuthSession(session)
      window.dispatchEvent(new Event('pos-auth-changed'))
      navigate('/dashboard', { replace: true })
    },
  })

  function submitInvitation(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    acceptMutation.mutate()
  }

  return (
    <div className="mx-auto w-full max-w-md rounded-3xl border border-graphite/10 bg-white/80 p-6 shadow-sm sm:p-8">
      <p className="text-[10px] font-bold uppercase tracking-[0.26em] text-graphite/50">Team invitation</p>
      <h2 className="mt-2 text-3xl font-black text-graphite">Join your team</h2>

      {!token && <p role="alert" className="mt-4 text-sm text-danger">This invitation link is incomplete. Ask the business owner to send a new invitation.</p>}
      {token && invitationQuery.isPending && <p className="mt-4 text-sm text-graphite/60">Checking your invitation...</p>}
      {invitationQuery.isError && <div role="alert" className="mt-4 space-y-3 text-sm text-danger">
        <p>This invitation is invalid or has expired. Ask the business owner to send a new invitation.</p>
      </div>}

      {invitationQuery.data && (
        <>
          <p className="mt-4 text-sm text-graphite/70">
            You are invited to join <strong className="text-graphite">{invitationQuery.data.business_name}</strong> as a {invitationQuery.data.role}.
          </p>
          <p className="mt-2 text-sm text-graphite/60">Create a password for {invitationQuery.data.email} to accept.</p>
          <form className="mt-6 space-y-4" onSubmit={submitInvitation}>
            <label className="grid gap-1 text-sm font-medium text-graphite">
              Password
              <input
                required
                type="password"
                minLength={12}
                pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{12,}"
                autoComplete="new-password"
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                className="w-full rounded-xl border border-graphite/10 bg-champagne-light px-4 py-3 text-sm outline-none focus:border-lime focus:ring-2 focus:ring-lime/30"
              />
              <small className="text-xs font-normal text-graphite/55">Use 12+ characters with upper/lowercase, a number, and a symbol.</small>
            </label>
            <label className="grid gap-1 text-sm font-medium text-graphite">
              Confirm password
              <input
                required
                type="password"
                minLength={12}
                autoComplete="new-password"
                value={passwordConfirmation}
                onChange={(event) => setPasswordConfirmation(event.target.value)}
                className="w-full rounded-xl border border-graphite/10 bg-champagne-light px-4 py-3 text-sm outline-none focus:border-lime focus:ring-2 focus:ring-lime/30"
              />
            </label>
            {acceptMutation.isError && <p role="alert" className="text-sm text-danger">We could not accept this invitation. It may have expired, already been used, or an account may already exist for this email. <Link className="font-semibold underline" to="/login">Sign in</Link> or ask the owner for help.</p>}
            <Button className="w-full" type="submit" disabled={acceptMutation.isPending}>
              {acceptMutation.isPending ? 'Joining team...' : 'Accept invitation'}
            </Button>
          </form>
        </>
      )}
    </div>
  )
}
