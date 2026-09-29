import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useForm, useWatch } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation } from '@tanstack/react-query'
import axios from 'axios'
import { Check, Eye, EyeOff, Loader2, UserRoundPlus, ArrowLeft } from 'lucide-react'
import { registerBusiness } from '../../api/auth'
import { Button } from '../../components/ui/Button'

const registerSchema = z.object({
  business_name: z.string().min(2, 'Business name is required.'),
  name: z.string().min(2, 'Your name is required.'),
  email: z.string().email('Enter a valid work email.'),
  password: z.string()
    .min(12, 'Password must be at least 12 characters.')
    .regex(/[a-z]/, 'Password must include a lowercase letter.')
    .regex(/[A-Z]/, 'Password must include an uppercase letter.')
    .regex(/[0-9]/, 'Password must include a number.')
    .regex(/[^A-Za-z0-9]/, 'Password must include a symbol.'),
  password_confirmation: z.string().min(12, 'Confirm your password.'),
}).refine((data) => data.password === data.password_confirmation, {
  message: 'Passwords do not match.',
  path: ['password_confirmation'],
})

type RegisterFormValues = z.infer<typeof registerSchema>

function getRegistrationError(error: unknown): string {
  if (!axios.isAxiosError(error)) {
    return 'Unable to create your business. Please try again.'
  }

  const response = error.response?.data as { message?: unknown; errors?: Record<string, unknown> } | undefined
  const firstValidationError = response?.errors
    ? Object.values(response.errors).flatMap((messages) => Array.isArray(messages) ? messages : [messages])[0]
    : undefined

  if (typeof firstValidationError === 'string') {
    return firstValidationError
  }

  if (error.response?.status === 429) {
    return 'Too many registration attempts. Please wait a minute and try again.'
  }

  if (error.response?.status === 500 || !error.response) {
    return 'We could not reach the service. Please try again shortly.'
  }

  if (typeof response?.message === 'string' && error.response.status < 500) {
    return response.message
  }

  return 'Unable to create your business. Please review the form and try again.'
}

export default function RegisterPage() {
  const navigate = useNavigate()
  const [showPassword, setShowPassword] = useState(false)
  const [showConfirmation, setShowConfirmation] = useState(false)

  const form = useForm<RegisterFormValues>({
    resolver: zodResolver(registerSchema),
    defaultValues: {
      business_name: '',
      name: '',
      email: '',
      password: '',
      password_confirmation: '',
    },
  })

  const password = useWatch({ control: form.control, name: 'password' }) || ''
  const passwordConfirmation = useWatch({ control: form.control, name: 'password_confirmation' }) || ''

  const passwordRequirements = [
    { label: 'At least 12 characters', met: password.length >= 12 },
    { label: 'One uppercase & lowercase letter', met: /[A-Z]/.test(password) && /[a-z]/.test(password) },
    { label: 'At least one number', met: /[0-9]/.test(password) },
    { label: 'At least one special symbol', met: /[^A-Za-z0-9]/.test(password) },
  ]

  const mutation = useMutation({
    mutationFn: registerBusiness,
    onSuccess: (data) => {
      localStorage.setItem('pos_token', data.token)
      localStorage.setItem('pos_business_id', data.business.id)
      localStorage.setItem('pos_user', JSON.stringify(data.user))
      window.dispatchEvent(new Event('pos-auth-changed'))
      navigate('/dashboard', { replace: true })
    },
  })

  return (
    <div className="mx-auto max-w-lg">
      <Link
        to="/"
        className="inline-flex items-center gap-1.5 text-xs font-semibold text-graphite/60 hover:text-graphite mb-6 transition"
      >
        <ArrowLeft size={14} /> Back to landing page
      </Link>

      <div className="mb-6 flex items-center justify-between gap-3">
        <div>
          <p className="text-[11px] font-bold uppercase tracking-[0.25em] text-graphite/50 font-heading">
            New Business Setup
          </p>
          <h2 className="mt-1 text-3xl font-extrabold tracking-tight text-graphite font-heading">
            Create account
          </h2>
        </div>
        <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-lime text-graphite shadow-sm">
          <UserRoundPlus className="h-6 w-6" />
        </div>
      </div>

      <form className="space-y-4" onSubmit={form.handleSubmit((values) => mutation.mutate(values))} noValidate>
        <div>
          <label className="mb-1.5 block text-xs font-bold text-graphite uppercase tracking-wider">
            Business / Store Name
          </label>
          <input
            placeholder="e.g. Westlands Supermarket Ltd"
            className="w-full rounded-xl border border-graphite/15 bg-white px-4 py-2.5 text-sm text-graphite outline-none focus:border-lime focus:ring-2 focus:ring-lime/30"
            {...form.register('business_name')}
          />
          {form.formState.errors.business_name && (
            <p className="mt-1 text-xs text-danger font-medium">{form.formState.errors.business_name.message}</p>
          )}
        </div>

        <div>
          <label className="mb-1.5 block text-xs font-bold text-graphite uppercase tracking-wider">
            Owner / Administrator Full Name
          </label>
          <input
            placeholder="e.g. Alex Morgan"
            className="w-full rounded-xl border border-graphite/15 bg-white px-4 py-2.5 text-sm text-graphite outline-none focus:border-lime focus:ring-2 focus:ring-lime/30"
            {...form.register('name')}
          />
          {form.formState.errors.name && (
            <p className="mt-1 text-xs text-danger font-medium">{form.formState.errors.name.message}</p>
          )}
        </div>

        <div>
          <label className="mb-1.5 block text-xs font-bold text-graphite uppercase tracking-wider">
            Work Email Address
          </label>
          <input
            type="email"
            placeholder="alex@store.ke"
            className="w-full rounded-xl border border-graphite/15 bg-white px-4 py-2.5 text-sm text-graphite outline-none focus:border-lime focus:ring-2 focus:ring-lime/30"
            {...form.register('email')}
          />
          {form.formState.errors.email && (
            <p className="mt-1 text-xs text-danger font-medium">{form.formState.errors.email.message}</p>
          )}
        </div>

        <div>
          <label htmlFor="reg-pwd" className="mb-1.5 block text-xs font-bold text-graphite uppercase tracking-wider">
            Master Password
          </label>
          <div className="relative">
            <input
              id="reg-pwd"
              type={showPassword ? 'text' : 'password'}
              autoComplete="new-password"
              placeholder="Min 12 chars with upper, lower, number, symbol"
              className="w-full rounded-xl border border-graphite/15 bg-white px-4 py-2.5 pr-11 text-sm text-graphite outline-none focus:border-lime focus:ring-2 focus:ring-lime/30"
              {...form.register('password')}
            />
            <button
              type="button"
              onClick={() => setShowPassword((v) => !v)}
              className="absolute right-3 top-1/2 -translate-y-1/2 p-1 text-graphite/50 hover:text-graphite transition"
              aria-label="Toggle password"
            >
              {showPassword ? <EyeOff size={16} /> : <Eye size={16} />}
            </button>
          </div>
          <ul className="mt-2.5 grid grid-cols-2 gap-1.5 text-[11px]">
            {passwordRequirements.map(({ label, met }) => (
              <li key={label} className={`flex items-center gap-1.5 ${met ? 'text-success font-semibold' : 'text-graphite/45'}`}>
                <Check size={13} className={met ? 'opacity-100 text-success' : 'opacity-30'} />
                <span>{label}</span>
              </li>
            ))}
          </ul>
        </div>

        <div>
          <label htmlFor="reg-pwd-conf" className="mb-1.5 block text-xs font-bold text-graphite uppercase tracking-wider">
            Confirm Password
          </label>
          <div className="relative">
            <input
              id="reg-pwd-conf"
              type={showConfirmation ? 'text' : 'password'}
              autoComplete="new-password"
              className="w-full rounded-xl border border-graphite/15 bg-white px-4 py-2.5 pr-11 text-sm text-graphite outline-none focus:border-lime focus:ring-2 focus:ring-lime/30"
              {...form.register('password_confirmation')}
            />
            <button
              type="button"
              onClick={() => setShowConfirmation((v) => !v)}
              className="absolute right-3 top-1/2 -translate-y-1/2 p-1 text-graphite/50 hover:text-graphite transition"
              aria-label="Toggle confirm password"
            >
              {showConfirmation ? <EyeOff size={16} /> : <Eye size={16} />}
            </button>
          </div>
          {passwordConfirmation.length > 0 && (
            <p className={`mt-1.5 text-xs font-medium ${password === passwordConfirmation ? 'text-success' : 'text-danger'}`}>
              {password === passwordConfirmation ? '✓ Passwords match' : '✕ Passwords do not match'}
            </p>
          )}
        </div>

        {mutation.isError && (
          <div className="rounded-xl border border-danger/20 bg-danger/10 p-3 text-xs text-danger font-medium">
            {getRegistrationError(mutation.error)}
          </div>
        )}

        <Button
          type="submit"
          variant="primary"
          size="lg"
          disabled={mutation.isPending}
          className="w-full mt-2"
        >
          {mutation.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : null}
          {mutation.isPending ? 'Creating your business...' : 'Register Business & Open Dashboard'}
        </Button>
      </form>

      <div className="mt-8 border-t border-graphite/10 pt-5 text-center text-xs text-graphite/65">
        Already registered?{' '}
        <Link to="/login" className="font-bold text-graphite underline-offset-4 hover:underline">
          Sign in instead
        </Link>
      </div>
    </div>
  )
}

