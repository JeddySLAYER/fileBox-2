function LoginForm() {

  return (
    <>
        <form action="" class="mt-8 space-y-4">
            <div>
                <label class="mb-1.5 block text-xs font-medium text-foreground">
                    Adresse email
                </label>
                <input type="email" class="h-11 w-full rounded-lg border border-border bg-background px-3 text-sm placeholder:text-muted-foreground focus:outline-accent focus:ring-1" placeholder='jeddy@ebusiness.com'/>
            </div>
            <div>
                <div class="mb-1.5 flex items-center justify-between">
                    <label class="block text-xs font-medium text-foreground">
                        Mot de passe
                    </label>
                    <a href="/forgot-password" class="text-xs font-medium text-primary brightness-110 hover:underline">
                        Mot de passe oublié ?
                    </a>
                </div>
                <input type="password" class="h-11 w-full rounded-lg border border-border bg-background px-3 text-sm focus:outline-accent focus:ring-1"/>
            </div>
            <label class="flex items-center gap-2 text-xs text-muted-foreground">
                <input type="checkbox" class="h-4 w-4 rounded border-border accent-primary"/>
                Se souvenir de moi
            </label>
            <a href="" class="inline-flex h-11 w-full items-center justify-center gap-2 rounded-lg bg-primary text-sm font-medium text-primary-foreground shadow-soft transition-all hover:brightness-110 active:scale-[0.99]">
                Se connecter
            </a>
        </form>
    </>
  )
}

export default LoginForm
