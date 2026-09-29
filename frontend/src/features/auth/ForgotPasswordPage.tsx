export default function ForgotPasswordPage() {
  return (
    <div className="mx-auto max-w-md rounded-3xl border border-graphite/10 bg-white/80 p-8 shadow-sm">
      <p className="text-[10px] font-bold uppercase tracking-[0.26em] text-graphite/50">Account recovery</p>
      <h2 className="mt-2 text-3xl font-black text-graphite">Reset password</h2>
      <p className="mt-4 text-sm text-graphite/60">Enter the email linked to your account and we will send a reset link.</p>
      <form className="mt-6 space-y-4">
        <input type="email" placeholder="name@business.com" className="w-full rounded-xl border border-graphite/10 bg-champagne-light px-4 py-3 text-sm outline-none focus:border-lime focus:ring-2 focus:ring-lime/30" />
        <button type="button" className="w-full rounded-xl bg-graphite px-4 py-3 text-sm font-semibold text-champagne">Send reset link</button>
      </form>
    </div>
  )
}
