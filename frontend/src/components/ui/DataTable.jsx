import EmptyState from '@/components/ui/EmptyState'
import LoadingScreen from '@/components/ui/LoadingScreen'
import Pagination from '@/components/ui/Pagination'
import { cn } from '@/lib/cn'

/**
 * @param {{ key: string, header: string, className?: string, cell: (row: any) => import('react').ReactNode }[]} columns
 */
export default function DataTable({
  columns,
  rows,
  rowKey = (row) => row.id,
  loading = false,
  emptyTitle = 'Aucun élément',
  meta = null,
  onPageChange,
  className,
}) {
  if (loading) return <LoadingScreen />

  if (!rows?.length) {
    return <EmptyState title={emptyTitle} />
  }

  return (
    <div className={cn('overflow-hidden rounded-xl border border-border bg-background', className)}>
      <div className="overflow-x-auto">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-border bg-muted/50 text-xs text-muted-foreground">
            <tr>
              {columns.map((col) => (
                <th key={col.key} className={cn('px-4 py-2.5 font-medium', col.className)}>
                  {col.header}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={rowKey(row)} className="border-b border-border last:border-0">
                {columns.map((col) => (
                  <td key={col.key} className={cn('px-4 py-3 align-middle', col.className)}>
                    {col.cell(row)}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {meta && onPageChange ? <Pagination meta={meta} onPageChange={onPageChange} /> : null}
    </div>
  )
}
