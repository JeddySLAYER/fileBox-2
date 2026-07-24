import { cn } from '@/lib/cn'

export default function Label({ className, children, ...props }) {
  return (
    <label className={cn('mb-1.5 block text-xs font-medium text-foreground', className)} {...props}>
      {children}
    </label>
  )
}
