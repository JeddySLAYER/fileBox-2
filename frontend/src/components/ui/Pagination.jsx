import Button from '@/components/ui/Button'

export default function Pagination({ meta, onPageChange }) {
  if (!meta || meta.last_page <= 1) return null

  const current = meta.current_page
  const last = meta.last_page
  const total = meta.total
  const from = meta.from ?? (current - 1) * meta.per_page + 1
  const to = meta.to ?? Math.min(current * meta.per_page, total)

  return (
    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3">
      <p className="text-xs text-muted-foreground">
        {from}–{to} sur {total}
      </p>
      <div className="flex items-center gap-2">
        <Button
          type="button"
          variant="secondary"
          size="sm"
          disabled={current <= 1}
          onClick={() => onPageChange(current - 1)}
        >
          Précédent
        </Button>
        <span className="text-xs text-muted-foreground">
          Page {current} / {last}
        </span>
        <Button
          type="button"
          variant="secondary"
          size="sm"
          disabled={current >= last}
          onClick={() => onPageChange(current + 1)}
        >
          Suivant
        </Button>
      </div>
    </div>
  )
}
