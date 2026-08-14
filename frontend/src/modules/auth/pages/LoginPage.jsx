import BrandMark from '@/components/BrandMark'
import LoginForm from '@/modules/auth/components/LoginForm'
import logo from '../../../assets/images/LogoEBA.png'


export default function LoginPage() {
  return (
    <div className="grid min-h-screen grid-cols-1 lg:grid-cols-[1fr_1.15fr]">
      <div className="flex flex-col px-6 py-10 md:px-12">
        <div className="flex justify-center lg:justify-start">
          <div className="flex items-center gap-3">
            <img src={logo} alt="Logo e-BA" className="w-40 h-40" />
          </div>
        </div>

        <div className="flex flex-1 items-center">
          <div className="mx-auto w-full max-w-sm animate-fade-in">
            <h1 className="text-3xl font-semibold tracking-tight">Bienvenue sur fileBox</h1>
            <p className="mt-2 text-sm text-muted-foreground">
              Entrez vos identifiants pour accéder à la GED
            </p>
            <LoginForm />
          </div>
        </div>

        <div className="text-xs text-muted-foreground">© 2026 fileBox · E-business Afrique</div>
      </div>

      <div className="relative hidden overflow-hidden bg-foreground lg:block">
        <div className="absolute inset-0 bg-[radial-gradient(circle_at_20%_20%,#d73a31_0%,transparent_45%),radial-gradient(circle_at_80%_70%,#c52020_0%,transparent_40%)] opacity-80" />
        <div className="relative flex h-full flex-col justify-end p-12 text-white">
          <p className="text-sm uppercase tracking-[0.2em] text-white/60">FileBox</p>
          <h2 className="mt-3 max-w-md text-3xl font-semibold leading-tight">
            Documents, versions, droits et validations au même endroit.
          </h2>
          </div>
      </div>
    </div>
  )
}
