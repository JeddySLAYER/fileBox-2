import LogoEBA from '../assets/images/LogoEBA.png'
import LoginForm from '../components/forms/LoginForm'

function Login() {

  return (
    <>
      <div class=" grid min-h-screen grid-cols-1 lg:grid-cols-[1fr_1.15fr]">
        <div class="flex flex-col px-6 py-10 md:px-12">
            <div class="flex justify-center">
                <img src={LogoEBA} alt="logo e-BA" class="h-48 w-48 rounded-2xl object-cover shadow-soft"/>
            </div>
            <div class="flex flex-1 items-center">
                <div class="mx-auto w-full max-w-sm animate-fade-in">
                    <h1 class="text-3xl font-semibold tracking-tight">
                        Bienvenue sur fileBox
                    </h1>
                    <p class="mt-2 text-sm text-muted-foreground">
                        Entrez vos identifiants pour vous connecter
                    </p>
                    <LoginForm></LoginForm>
                    <p class="mt-8 text-xs">
                        En vous connectant vous acceptez nos
                        <span> </span>
                        <a href="#" class="underline">
                            conditions d'utilisations
                        </a>
                        .
                    </p>
                </div>
            </div>
            <div class="text-xs text-muted-foreground">
                © 2026 fileBox · E-business Afrique
            </div>
        </div>
        <div class="border-4 border-amber-400 relative hidden overflow-hidden bg-foreground lg:block">

        </div>
      </div>
    </>
  )
}

export default Login
