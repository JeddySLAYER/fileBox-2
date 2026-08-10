import { cn } from '@/lib/cn'

export default function Card({ className, children, ref, ...props }) {
  return (
    <div
      ref={ref}
      className={cn('rounded-xl border border-border bg-background p-5 shadow-soft', className)}
      {...props}
    >
      {children}
    </div>
  )
}
