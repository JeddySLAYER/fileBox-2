import Spinner from '@/components/ui/Spinner'

export default function LoadingScreen({ label = 'Chargement…' }) {
  return (
    <div className="flex min-h-[40vh] flex-col items-center justify-center gap-3 text-sm text-muted-foreground">
      <Spinner className="h-6 w-6" />
      <span>{label}</span>
    </div>
  )
}
