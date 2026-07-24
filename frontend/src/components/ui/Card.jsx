import { cn } from '@/lib/cn'

export default function Card({ className, children }) {
  return (
    <div className={cn('rounded-xl border border-border bg-background p-5 shadow-soft', className)}>
      {children}
    </div>
  )
}
