import { cn } from '@/lib/cn'

export default function Spinner({ className }) {
  return (
    <span
      className={cn(
        'inline-block h-5 w-5 animate-spin rounded-full border-2 border-border border-t-primary',
        className,
      )}
      aria-hidden
    />
  )
}
