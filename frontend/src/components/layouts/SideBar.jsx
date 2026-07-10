import LogoEBA from '../../assets/images/LogoEBA.png'

function SideBar() {

  return (
    <>
        <aside class="fixed inset-y-0 left-0 z-30 hidden w-64 flex-col border-r border-border lg:flex">
            <div class="flex h-28 items-center justify-center border-b border-border px-5">
                <a href="/dashboard" class="flex items-center gap-2 active" data-status="active" aria-current="page">
                    <img src={LogoEBA} alt="logo e-BA" class="h-20 w-20 rounded-xl object-cover"/>
                </a>
            </div>
            <nav class="flex-1 overflow-y-auto px-3 py-5">
                <div class="mb-5"></div>
                <div class="mb-5"></div>
                <div class="mb-5"></div>
            </nav>
            <div class="border-t border-border p-3">
                <a href="/" class="flex items-center gap-2 rounded-lg px-3 py-2 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground">
                    Déconnexion
                </a>
            </div>
        </aside>
    </>
  )
}

export default SideBar
