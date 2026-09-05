import { Bell, Menu, Search } from 'lucide-react';
import { useSidebar } from './SidebarContext';

export default function Header() {
    const { toggleMobile } = useSidebar();

    return (
        <header className="sticky top-0 z-30 flex h-14 items-center gap-2 border-b border-slate-200/80 bg-white/90 px-3 backdrop-blur-md sm:h-16 sm:gap-4 sm:px-6">
            <button
                type="button"
                onClick={toggleMobile}
                className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 transition hover:bg-slate-50 lg:hidden"
                aria-label="Ouvrir le menu"
            >
                <Menu className="h-[18px] w-[18px]" />
            </button>

            <div className="relative min-w-0 flex-1 lg:mx-auto lg:max-w-xl">
                <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400 sm:left-3.5" />
                <input
                    type="search"
                    placeholder="Rechercher une commande, un client…"
                    className="h-10 w-full rounded-xl border border-slate-200 bg-slate-50 pl-9 pr-3 text-sm text-slate-800 outline-none transition placeholder:text-slate-400 focus:border-blue-300 focus:bg-white focus:ring-4 focus:ring-blue-500/10 sm:pl-10 sm:pr-16"
                />
                <kbd className="pointer-events-none absolute right-3 top-1/2 hidden -translate-y-1/2 rounded-md border border-slate-200 bg-white px-1.5 py-0.5 text-[10px] font-semibold tracking-wide text-slate-400 sm:inline">
                    CTRL K
                </kbd>
            </div>

            <div className="flex shrink-0 items-center gap-1.5 sm:gap-3">
                <button
                    type="button"
                    className="inline-flex h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white px-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50 sm:px-3"
                >
                    <span className="text-base leading-none">🇫🇷</span>
                    <span className="hidden sm:inline">Fr</span>
                </button>

                <button
                    type="button"
                    className="relative inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 transition hover:bg-slate-50"
                    aria-label="Notifications"
                >
                    <Bell className="h-[18px] w-[18px]" />
                    <span className="absolute -right-1 -top-1 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-bold text-white ring-2 ring-white">
                        3
                    </span>
                </button>

                <div className="flex items-center gap-2 rounded-xl border border-slate-200 bg-white py-1.5 pl-1.5 pr-1.5 sm:pr-3">
                    <div className="flex h-8 w-8 items-center justify-center rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 text-xs font-bold text-white">
                        B
                    </div>
                    <div className="hidden leading-tight md:block">
                        <div className="text-sm font-semibold text-slate-800">Brahim</div>
                        <div className="text-[11px] font-medium text-slate-400">Super Admin</div>
                    </div>
                </div>
            </div>
        </header>
    );
}
