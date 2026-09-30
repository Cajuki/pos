import type { ReactNode } from 'react'

interface Column<T> {
  header: string
  accessor: keyof T
  render?: (row: T) => ReactNode
}

interface DataTableProps<T extends Record<string, unknown>> {
  columns: Column<T>[]
  rows: T[]
  loading?: boolean
  emptyMessage?: string
}

export function DataTable<T extends Record<string, unknown>>({ columns, rows, loading, emptyMessage = 'No data available.' }: DataTableProps<T>) {
  if (loading) {
    return (
      <div className="rounded-2xl border border-graphite/10 bg-white p-6 text-sm text-graphite/60">
        Loading records...
      </div>
    )
  }

  if (rows.length === 0) {
    return (
      <div className="rounded-2xl border border-dashed border-graphite/15 bg-champagne-light p-8 text-center text-sm text-graphite/60">
        {emptyMessage}
      </div>
    )
  }

  return (
    <div className="overflow-x-auto rounded-2xl border border-graphite/10 bg-white">
      <table className="min-w-full text-left text-sm">
        <thead className="bg-champagne-light text-graphite">
          <tr>
            {columns.map((column) => (
              <th key={String(column.accessor)} className="px-4 py-3 font-semibold">
                {column.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row, index) => (
            <tr key={index} className="border-t border-graphite/10">
              {columns.map((column) => (
                <td key={String(column.accessor)} className="px-4 py-3 text-graphite/75">
                  {column.render ? column.render(row) : String(row[column.accessor] ?? '-')}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
