import { useEffect, useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { Bell, ChevronRight, Globe, LogOut, Menu, Search, UserRound, X } from 'lucide-react';
import { useSidebar } from './SidebarContext';
import { useAuth } from '../../contexts/AuthContext';
import BrandLogo, { BrandIcon } from './BrandLogo';

function initials(name = '') {
    return (
        name
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((part) => part[0]?.toUpperCase() ?? '')
            .join('') || '?'
    );
}

export default function Header() {
    const { toggleMobile, closeMobile } = useSidebar();
    const { user, logout } = useAuth();
    const navigate = useNavigate();
    const location = useLocation();
    const [searchOpen, setSearchOpen] = useState(false);
    const [menuOpen, setMenuOpen] = useState(false);
    const [langOpen, setLangOpen] = useState(false);

    useEffect(() => {
        setSearchOpen(false);
        setMenuOpen(false);
        setLangOpen(false);
    }, [location.pathname]);

    function openDrawer() {
        setMenuOpen(false);
        setSearchOpen(false);
        toggleMobile();
    }

    function toggleAccount() {
        setMenuOpen((open) => {
            const next = !open;
            if (next) {
                setSearchOpen(false);
                closeMobile();
            }
            return next;
        });
    }

    async function handleLogout() {
        setMenuOpen(false);
        try {
            await logout();
        } finally {
            navigate('/login', { replace: true });
        }
    }

    return (
        <header className="sticky top-0 z-30 border-b border-slate-200/80 bg-white/95 backdrop-blur-md">
            <div className="flex h-14 items-center gap-1.5 px-3 sm:h-16 sm:gap-3 sm:px-6">
                <button
                    type="button"
                    onClick={openDrawer}
                    className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 transition hover:bg-slate-50 lg:hidden"
                    aria-label="Ouvrir le menu"
                >
                    <Menu className="h-[18px] w-[18px]" />
                </button>

                <div className="flex min-w-0 items-center gap-2 lg:hidden">
                    <BrandIcon className="h-8 w-8 shadow-none" />
                    <BrandLogo tone="light" size="sm" />
                </div>

                <div className="relative mx-auto hidden min-w-0 flex-1 lg:block lg:max-w-xl">
                    <Search className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <input
                        type="search"
                        placeholder="Rechercher une commande, un client…"
                        className="h-10 w-full rounded-xl border border-slate-200 bg-slate-50 pl-10 pr-16 text-sm text-slate-800 outline-none transition placeholder:text-slate-400 focus:border-blue-300 focus:bg-white focus:ring-4 focus:ring-blue-500/10"
                    />
                    <kbd className="pointer-events-none absolute right-3 top-1/2 hidden -translate-y-1/2 rounded-md border border-slate-200 bg-white px-1.5 py-0.5 text-[10px] font-semibold tracking-wide text-slate-400 sm:inline">
                        CTRL K
                    </kbd>
                </div>

                <div className="ml-auto flex shrink-0 items-center gap-1.5 sm:gap-3">
                    <button
                        type="button"
                        aria-label={searchOpen ? 'Fermer la recherche' : 'Rechercher'}
                        aria-expanded={searchOpen}
                        onClick={() => {
                            setMenuOpen(false);
                            setSearchOpen((value) => !value);
                        }}
                        className="inline-flex h-10 w-10 items-center justify-center rounded-xl text-slate-600 transition hover:bg-slate-50 lg:hidden"
                    >
                        {searchOpen ? <X className="h-[18px] w-[18px]" /> : <Search className="h-[18px] w-[18px]" />}
                    </button>

                    <button
                        type="button"
                        className="relative inline-flex h-10 w-10 items-center justify-center rounded-xl text-slate-600 transition hover:bg-slate-50 lg:border lg:border-slate-200 lg:bg-white"
                        aria-label="Notifications"
                    >
                        <Bell className="h-[18px] w-[18px]" />
                    </button>

                    <div className="relative">
                        <button
                            type="button"
                            onClick={toggleAccount}
                            className="inline-flex h-10 w-10 items-center justify-center rounded-full bg-blue-600 text-xs font-bold text-white md:h-auto md:w-auto md:gap-2 md:rounded-xl md:border md:border-slate-200 md:bg-white md:py-1.5 md:pl-1.5 md:pr-3"
                            aria-expanded={menuOpen}
                            aria-haspopup="menu"
                            aria-label="Ouvrir le menu compte"
                        >
                            <span className="md:flex md:h-8 md:w-8 md:items-center md:justify-center md:rounded-full md:bg-blue-600 md:text-xs md:font-bold md:text-white">
                                {initials(user?.name)}
                            </span>
                            <span className="hidden text-left leading-tight md:block">
                                <span className="block text-sm font-semibold text-slate-800">{user?.name}</span>
                                <span className="block text-[11px] font-medium text-slate-400">{user?.role_label}</span>
                            </span>
                        </button>

                        {menuOpen ? (
                            <>
                                <button
                                    type="button"
                                    aria-label="Fermer le menu compte"
                                    className="fixed inset-0 z-[60] bg-slate-900/40 lg:bg-transparent"
                                    onClick={() => setMenuOpen(false)}
                                />
                                <div
                                    role="menu"
                                    className="absolute right-0 z-[70] mt-2 w-[min(calc(100vw-1.5rem),280px)] rounded-2xl border border-slate-200 bg-white shadow-2xl shadow-slate-900/15"
                                >
                                    <div className="flex items-center gap-3 px-4 py-4">
                                        <span className="flex h-11 w-11 items-center justify-center rounded-full bg-blue-600 text-sm font-bold text-white">
                                            {initials(user?.name)}
                                        </span>
                                        <div className="min-w-0">
                                            <div className="truncate text-sm font-bold text-slate-900">{user?.name}</div>
                                            <div className="truncate text-xs font-medium text-slate-400">{user?.role_label}</div>
                                        </div>
                                    </div>

                                    <div className="border-t border-slate-100 py-1.5">
                                        <Link
                                            to="/profil"
                                            role="menuitem"
                                            onClick={() => setMenuOpen(false)}
                                            className="flex items-center gap-3 px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
                                        >
                                            <UserRound className="h-4 w-4 text-slate-500" />
                                            Mon compte
                                        </Link>
                                        <button
                                            type="button"
                                            role="menuitem"
                                            aria-expanded={langOpen}
                                            onClick={() => setLangOpen((value) => !value)}
                                            className="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm font-medium text-slate-700 hover:bg-slate-50"
                                        >
                                            <Globe className="h-4 w-4 text-slate-500" />
                                            <span className="flex-1">Langue</span>
                                            <ChevronRight
                                                className={['h-4 w-4 text-slate-400 transition', langOpen ? 'rotate-90' : ''].join(' ')}
                                            />
                                        </button>
                                        {langOpen ? (
                                            <div className="px-4 pb-2">
                                                <div className="rounded-xl bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-700">
                                                    Français
                                                </div>
                                            </div>
                                        ) : null}
                                    </div>

                                    <div className="border-t border-slate-100 p-2">
                                        <button
                                            type="button"
                                            role="menuitem"
                                            onClick={handleLogout}
                                            className="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold text-red-600 hover:bg-red-50"
                                        >
                                            <LogOut className="h-4 w-4" />
                                            Se déconnecter
                                        </button>
                                    </div>
                                </div>
                            </>
                        ) : null}
                    </div>
                </div>
            </div>

            {searchOpen ? (
                <div className="px-3 pb-3 lg:hidden">
                    <div className="relative">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            type="search"
                            autoFocus
                            placeholder="Rechercher une commande, un client…"
                            className="h-10 w-full rounded-xl border border-slate-200 bg-slate-50 pl-9 pr-3 text-sm text-slate-800 outline-none transition placeholder:text-slate-400 focus:border-blue-300 focus:bg-white focus:ring-4 focus:ring-blue-500/10"
                        />
                    </div>
                </div>
            ) : null}
        </header>
    );
}
