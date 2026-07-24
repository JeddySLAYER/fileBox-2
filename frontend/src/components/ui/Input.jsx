import { cn } from '@/lib/cn'

export default function Input({ className, ...props }) {
  return (
    <input
      className={cn(
        'h-11 w-full rounded-lg border border-border bg-background px-3 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary disabled:opacity-50',
        className,
      )}
      {...props}
    />
  )
}
