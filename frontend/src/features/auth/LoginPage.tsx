import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation } from '@tanstack/react-query'
import axios from 'axios'
import { Eye, EyeOff, Loader2, Lock, Mail, ShieldCheck, ArrowLeft } from 'lucide-react'
import { login, saveAuthSession } from '../../api/auth'
import { cn } from '../../lib/utils'
import { Button } from '../../components/ui/Button'

const loginSchema = z.object({
  email: z.string().trim().email('Please enter a valid email address.'),
  password: z.string().min(1, 'Enter your password.'),
})

type LoginFormValues = z.infer<typeof loginSchema>

export default function LoginPage() {
  const navigate = useNavigate()
  const [showPassword, setShowPassword] = useState(false)

  const form = useForm<LoginFormValues>({
    resolver: zodResolver(loginSchema),
    defaultValues: {
      email: '',
      password: '',
    },
  })

  const mutation = useMutation({
    mutationFn: login,
    onSuccess: (data) => {
      saveAuthSession({ token: data.token, user: data.user, business: data.businesses[0] })
      window.dispatchEvent(new Event('pos-auth-changed'))
      navigate('/dashboard', { replace: true })
    },
  })

  const onSubmit = (values: LoginFormValues) => {
    mutation.mutate({
      email: values.email,
      password: values.password,
      device_name: 'poss-web',
    })
  }

  function getLoginError(error: unknown): string {
    if (!axios.isAxiosError(error)) return 'Unable to sign in. Please try again.'
    if (error.response?.status === 422) return 'Email or password is incorrect.'
    if (error.response?.status === 429) return 'Too many sign-in attempts. Wait a minute and try again.'
    if (error.response?.status === 503 || !error.response) return 'The service is unavailable. Check your connection and try again.'
    return 'We could not sign you in. Please try again.'
  }

  return (
    <div className="mx-auto max-w-md">
      <Link
        to="/"
        className="inline-flex items-center gap-1.5 text-xs font-semibold text-graphite/60 hover:text-graphite mb-6 transition"
      >
        <ArrowLeft size={14} /> Back to landing page
      </Link>

      <div className="mb-6 flex items-center justify-between gap-3">
        <div>
          <p className="text-[11px] font-bold uppercase tracking-[0.25em] text-graphite/50 font-heading">
            Secure Access
          </p>
          <h2 className="mt-1 text-3xl font-extrabold tracking-tight text-graphite font-heading">
            Sign in
          </h2>
        </div>
        <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-lime text-graphite shadow-sm">
          <ShieldCheck className="h-6 w-6" />
        </div>
      </div>

      <form className="space-y-4" onSubmit={form.handleSubmit(onSubmit)} noValidate>
        <div>
          <label htmlFor="email" className="mb-1.5 block text-xs font-bold text-graphite uppercase tracking-wider">
            Email address
          </label>
          <div className="relative">
            <Mail className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-graphite/40" />
            <input
              id="email"
              type="email"
              autoComplete="email"
              placeholder="name@business.com"
              className={cn(
                'w-full rounded-xl border bg-white pl-10 pr-4 py-3 text-sm text-graphite outline-none transition focus:border-lime focus:ring-2 focus:ring-lime/30',
                form.formState.errors.email ? 'border-danger bg-danger/5' : 'border-graphite/15',
              )}
              {...form.register('email')}
            />
          </div>
          {form.formState.errors.email && (
            <p className="mt-1.5 text-xs text-danger font-medium">{form.formState.errors.email.message}</p>
          )}
        </div>

        <div>
          <label htmlFor="password" className="mb-1.5 block text-xs font-bold text-graphite uppercase tracking-wider">
            Password
          </label>
          <div className="relative">
            <Lock className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-graphite/40" />
            <input
              id="password"
              type={showPassword ? 'text' : 'password'}
              autoComplete="current-password"
              placeholder="••••••••••••"
              className={cn(
                'w-full rounded-xl border bg-white pl-10 pr-11 py-3 text-sm text-graphite outline-none transition focus:border-lime focus:ring-2 focus:ring-lime/30',
                form.formState.errors.password ? 'border-danger bg-danger/5' : 'border-graphite/15',
              )}
              {...form.register('password')}
            />
            <button
              type="button"
              onClick={() => setShowPassword((value) => !value)}
              className="absolute right-3 top-1/2 -translate-y-1/2 p-1 text-graphite/50 hover:text-graphite transition"
              aria-label={showPassword ? 'Hide password' : 'Show password'}
            >
              {showPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
            </button>
          </div>
          {form.formState.errors.password && (
            <p className="mt-1.5 text-xs text-danger font-medium">{form.formState.errors.password.message}</p>
          )}
        </div>

        {mutation.isError && (
          <div className="rounded-xl border border-danger/20 bg-danger/10 p-3 text-xs text-danger font-medium">
            {getLoginError(mutation.error)}
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
          {mutation.isPending ? 'Signing in...' : 'Sign in to Workspace'}
        </Button>
      </form>

      <div className="mt-8 border-t border-graphite/10 pt-5 text-center text-xs text-graphite/65">
        Need to register a new store?{' '}
        <Link to="/register" className="font-bold text-graphite underline-offset-4 hover:underline">
          Create an account
        </Link>
      </div>
    </div>
  )
}

