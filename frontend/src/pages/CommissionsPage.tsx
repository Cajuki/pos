import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { getCommissionEntries, getBusinessSettings, updateCommissionStatus } from '../api/commerce'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'
import { formatCurrency } from '../lib/utils'

export default function CommissionsPage() {
  const queryClient = useQueryClient()
  const [payoutReferences, setPayoutReferences] = useState<Record<number, string>>({})
  const entriesQuery = useQuery({ queryKey: ['commission-entries'], queryFn: getCommissionEntries })
  const settingsQuery = useQuery({ queryKey: ['business-settings'], queryFn: getBusinessSettings })
  const mutation = useMutation({
    mutationFn: ({ id, status }: { id: number; status: 'approved' | 'paid' }) => updateCommissionStatus(id, status, payoutReferences[id]),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['commission-entries'] }),
  })
  const currency = settingsQuery.data?.currency ?? 'KES'
  const summary = entriesQuery.data?.summary
  const cards = [
    ['Pending approval', summary?.pending],
    ['Approved to pay', summary?.approved],
    ['Paid', summary?.paid],
    ['Refund reversals', summary?.reversed],
  ] as const

  return (
    <section className="module-page w-full">
      <div className="mb-6"><p className="eyebrow">Team performance</p><h1>Commissions</h1><p className="text-sm text-graphite/60">Commissions are earned from paid sales, with refunds recorded as reversals. Approval and payout records are separate from cash collection.</p></div>
      <div className="mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {cards.map(([label, amount]) => <Card key={label} className="p-4"><p className="text-sm text-graphite/60">{label}</p><strong className="mt-1 block text-xl">{formatCurrency(Number(amount ?? 0), currency)}</strong></Card>)}
      </div>
      {entriesQuery.isError && <p role="alert" className="mb-4 text-sm text-danger">Commission records could not be loaded.</p>}
      {mutation.isError && <p role="alert" className="mb-4 text-sm text-danger">Commission update failed. Check the required payout reference and current status.</p>}
      <Card className="w-full overflow-x-auto p-5">
        <table className="w-full min-w-[800px] text-left text-sm">
          <thead className="border-b border-graphite/10 text-xs uppercase text-graphite/55"><tr><th className="px-3 py-3 font-medium">Staff</th><th className="px-3 py-3 font-medium">Service / product</th><th className="px-3 py-3 font-medium">Sale</th><th className="px-3 py-3 font-medium">Earned</th><th className="px-3 py-3 font-medium">Reversed</th><th className="px-3 py-3 font-medium">Status</th><th className="px-3 py-3 font-medium">Action</th></tr></thead>
          <tbody className="divide-y divide-graphite/10">
            {(entriesQuery.data?.data ?? []).map((entry) => <tr key={entry.id}>
              <td className="px-3 py-3">{entry.staff?.name ?? entry.staff_profile?.name ?? 'Staff'}</td>
              <td className="px-3 py-3">{entry.sale_item.product_name}</td>
              <td className="px-3 py-3">{entry.sale.receipt_number}</td>
              <td className="px-3 py-3">{formatCurrency(Number(entry.earned_amount), currency)}</td>
              <td className="px-3 py-3">{formatCurrency(Number(entry.reversed_amount), currency)}</td>
              <td className="px-3 py-3 capitalize">{entry.status}</td>
              <td className="px-3 py-3">
                {entry.status === 'pending' && <Button variant="secondary" disabled={mutation.isPending} onClick={() => mutation.mutate({ id: entry.id, status: 'approved' })}>Approve</Button>}
                {entry.status === 'approved' && <div className="flex gap-2"><input aria-label="Payout reference" value={payoutReferences[entry.id] ?? ''} onChange={(event) => setPayoutReferences((current) => ({ ...current, [entry.id]: event.target.value }))} placeholder="Payout reference" className="min-w-36 rounded-lg border border-graphite/10 px-2 py-1" /><Button variant="secondary" disabled={mutation.isPending || !payoutReferences[entry.id]?.trim()} onClick={() => mutation.mutate({ id: entry.id, status: 'paid' })}>Record paid</Button></div>}
                {entry.status === 'paid' && <span className="text-xs text-graphite/60">{entry.payout_reference || 'No reference'}</span>}
              </td>
            </tr>)}
          </tbody>
        </table>
        {entriesQuery.isPending && <p className="py-8 text-center text-sm text-graphite/60">Loading commission records…</p>}
        {!entriesQuery.isPending && !entriesQuery.isError && !entriesQuery.data?.data.length && <p className="py-8 text-center text-sm text-graphite/60">No eligible commissions yet.</p>}
      </Card>
    </section>
  )
}
