import { cn } from '@/lib/cn'

const variants = {
  primary:
    'bg-primary text-primary-foreground shadow-soft hover:brightness-110 active:scale-[0.99]',
  secondary:
    'bg-secondary text-secondary-foreground border border-border hover:bg-muted',
  ghost: 'text-muted-foreground hover:bg-muted hover:text-foreground',
  danger: 'bg-accent text-accent-foreground hover:brightness-95',
}

const sizes = {
  sm: 'h-9 px-3 text-xs',
  md: 'h-11 px-4 text-sm',
  lg: 'h-12 px-5 text-sm',
}

export default function Button({
  as: Comp = 'button',
  variant = 'primary',
  size = 'md',
  className,
  type,
  disabled,
  children,
  ...props
}) {
  return (
    <Comp
      type={Comp === 'button' ? type ?? 'button' : undefined}
      disabled={disabled}
      className={cn(
        'inline-flex items-center justify-center gap-2 rounded-lg font-medium transition-all disabled:pointer-events-none disabled:opacity-50',
        variants[variant],
        sizes[size],
        className,
      )}
      {...props}
    >
      {children}
    </Comp>
  )
}
