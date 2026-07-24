/** Marque textuelle — logo image à remplacer quand disponible */
export default function BrandMark({ size = 'md' }) {
  const box = size === 'lg' ? 'h-16 w-16 text-xl' : size === 'sm' ? 'h-9 w-9 text-xs' : 'h-12 w-12 text-sm'

  return (
    <div
      className={`flex ${box} items-center justify-center rounded-xl bg-primary font-semibold text-primary-foreground shadow-soft`}
      aria-hidden
    >
      FB
    </div>
  )
}
