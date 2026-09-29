export default function EmailVerificationPage() {
  return (
    <div className="mx-auto max-w-md rounded-3xl border border-graphite/10 bg-white/80 p-8 shadow-sm">
      <p className="text-[10px] font-bold uppercase tracking-[0.26em] text-graphite/50">Check your inbox</p>
      <h2 className="mt-2 text-3xl font-black text-graphite">Verify your email</h2>
      <p className="mt-4 text-sm text-graphite/60">We sent a verification link to your email address. Please open it to continue.</p>
      <button type="button" className="mt-6 w-full rounded-xl bg-graphite px-4 py-3 text-sm font-semibold text-champagne">Resend verification</button>
    </div>
  )
}
