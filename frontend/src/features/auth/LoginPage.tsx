import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation } from '@tanstack/react-query'
import { Eye, EyeOff, Loader2, Lock, Mail, ShieldCheck, Sparkles, ArrowLeft } from 'lucide-react'
import { login } from '../../api/auth'
import { cn } from '../../lib/utils'
import { Button } from '../../components/ui/Button'

const loginSchema = z.object({
  email: z.string().email('Please enter a valid email address.'),
  password: z.string().min(8, 'Password must be at least 8 characters.'),
  remember: z.boolean().optional(),
})

type LoginFormValues = z.infer<typeof loginSchema>

export default function LoginPage() {
  const navigate = useNavigate()
  const [showPassword, setShowPassword] = useState(false)

  const form = useForm<LoginFormValues>({
    resolver: zodResolver(loginSchema),
    defaultValues: {
      email: 'admin@demo.test',
      password: 'poss-demo-2026',
      remember: true,
    },
  })

  const mutation = useMutation({
    mutationFn: login,
    onSuccess: (data) => {
      localStorage.setItem('pos_token', data.token)
      localStorage.setItem('pos_user', JSON.stringify(data.user))
      if (data.businesses.length > 0) {
        localStorage.setItem('pos_business_id', data.businesses[0].id)
      }
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

  function fillDemoCredentials() {
    form.setValue('email', 'admin@demo.test')
    form.setValue('password', 'poss-demo-2026')
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

      {/* Demo Credentials Quick-Fill Banner */}
      <div className="mb-6 rounded-2xl border border-lime-dark/30 bg-lime/15 p-3.5 text-sm text-graphite">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2">
            <Sparkles className="h-4 w-4 text-graphite shrink-0" />
            <span className="text-xs font-semibold">Demo credentials pre-filled</span>
          </div>
          <button
            type="button"
            onClick={fillDemoCredentials}
            className="text-xs font-bold underline underline-offset-2 hover:text-graphite cursor-pointer"
          >
            Refill demo
          </button>
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
              placeholder="e.g. admin@demo.test"
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

        <div className="flex items-center justify-between text-xs font-medium pt-1">
          <label className="flex items-center gap-2 text-graphite/80 cursor-pointer">
            <input
              type="checkbox"
              className="h-4 w-4 rounded border-graphite/20 text-lime focus:ring-lime"
              {...form.register('remember')}
            />
            Remember me
          </label>
          <Link to="/forgot-password" className="text-graphite/70 hover:text-graphite underline-offset-4 hover:underline">
            Forgot password?
          </Link>
        </div>

        {mutation.isError && (
          <div className="rounded-xl border border-danger/20 bg-danger/10 p-3 text-xs text-danger font-medium">
            Could not sign you in. Please check your credentials or click "Refill demo" to use test account.
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

