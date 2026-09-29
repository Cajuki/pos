export default function ResetPasswordPage() {
  return (
    <div className="mx-auto max-w-md rounded-3xl border border-graphite/10 bg-white/80 p-8 shadow-sm">
      <p className="text-[10px] font-bold uppercase tracking-[0.26em] text-graphite/50">Security</p>
      <h2 className="mt-2 text-3xl font-black text-graphite">Set a new password</h2>
      <form className="mt-6 space-y-4">
        <input type="password" placeholder="New password" className="w-full rounded-xl border border-graphite/10 bg-champagne-light px-4 py-3 text-sm outline-none focus:border-lime focus:ring-2 focus:ring-lime/30" />
        <input type="password" placeholder="Confirm new password" className="w-full rounded-xl border border-graphite/10 bg-champagne-light px-4 py-3 text-sm outline-none focus:border-lime focus:ring-2 focus:ring-lime/30" />
        <button type="button" className="w-full rounded-xl bg-lime px-4 py-3 text-sm font-bold text-graphite">Update password</button>
      </form>
    </div>
  )
}
