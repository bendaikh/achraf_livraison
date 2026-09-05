import { NavLink, useLocation } from 'react-router-dom';
import { ChevronLeft, ChevronRight, X } from 'lucide-react';
import { modules, getActiveModule } from '../../navigation';
import { useSidebar } from './SidebarContext';

function ModuleItem({ mod, collapsed, onNavigate }) {
    const location = useLocation();
    const active = getActiveModule(location.pathname)?.id === mod.id;
    const Icon = mod.icon;

    return (
        <NavLink
            to={mod.to}
            title={collapsed ? mod.label : undefined}
            onClick={onNavigate}
            className={[
                'group relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors',
                collapsed ? 'justify-center px-2' : '',
                active
                    ? 'bg-blue-600 text-white shadow-lg shadow-blue-600/25'
                    : 'text-slate-300 hover:bg-slate-700/60 hover:text-white',
            ].join(' ')}
        >
            <Icon className="h-[18px] w-[18px] shrink-0 opacity-90" strokeWidth={2} />
            {!collapsed ? <span className="flex-1 truncate">{mod.label}</span> : null}
            {!collapsed && mod.badge ? (
                <span className="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-emerald-500 px-1.5 text-[11px] font-semibold text-white">
                    {mod.badge}
                </span>
            ) : null}
            {collapsed && mod.badge ? (
                <span className="absolute right-1.5 top-1.5 h-2 w-2 rounded-full bg-emerald-500" />
            ) : null}
        </NavLink>
    );
}

export default function Sidebar() {
    const { collapsed, mobileOpen, closeMobile, toggleCollapsed } = useSidebar();
    const compact = collapsed && !mobileOpen;

    return (
        <>
            {mobileOpen ? (
                <button
                    type="button"
                    aria-label="Fermer le menu"
                    className="fixed inset-0 z-40 bg-slate-900/50 backdrop-blur-[1px] lg:hidden"
                    onClick={closeMobile}
                />
            ) : null}

            <aside
                className={[
                    'fixed inset-y-0 left-0 z-50 flex flex-col bg-[#1e293b] text-white transition-all duration-300',
                    compact ? 'w-[80px]' : 'w-[260px]',
                    mobileOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
                ].join(' ')}
            >
                <div
                    className={[
                        'flex items-center gap-3 pb-2 pt-5',
                        compact ? 'justify-center px-2' : 'px-5',
                    ].join(' ')}
                >
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-blue-400 to-blue-700 shadow-lg shadow-blue-900/40">
                        <div className="h-4 w-4 rotate-12 rounded-sm bg-white/95 shadow-sm" />
                    </div>
                    {!compact ? (
                        <div className="min-w-0 flex-1">
                            <div className="truncate text-lg font-bold tracking-tight">Lavafast</div>
                            <div className="truncate text-[11px] font-medium text-slate-400">
                                Livraison
                            </div>
                        </div>
                    ) : null}
                    <button
                        type="button"
                        onClick={closeMobile}
                        className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-300 hover:bg-slate-700 lg:hidden"
                        aria-label="Fermer"
                    >
                        <X className="h-4 w-4" />
                    </button>
                </div>

                <nav className="mt-4 flex-1 space-y-1 overflow-y-auto px-3 pb-4">
                    {modules.map((mod) => (
                        <ModuleItem
                            key={mod.id}
                            mod={mod}
                            collapsed={compact}
                            onNavigate={closeMobile}
                        />
                    ))}
                </nav>

                <div className={['pb-4', compact ? 'px-2' : 'px-3'].join(' ')}>
                    <button
                        type="button"
                        onClick={toggleCollapsed}
                        className="mb-3 hidden w-full items-center justify-center gap-2 rounded-xl border border-slate-600/60 px-3 py-2 text-xs font-semibold text-slate-300 transition hover:bg-slate-700/60 hover:text-white lg:flex"
                        aria-label={collapsed ? 'Développer le menu' : 'Réduire le menu'}
                    >
                        {collapsed ? (
                            <ChevronRight className="h-4 w-4" />
                        ) : (
                            <>
                                <ChevronLeft className="h-4 w-4" />
                                Réduire
                            </>
                        )}
                    </button>
                    {!compact ? (
                        <div className="px-1 text-center text-[10px] text-slate-500">
                            lavafast-livraison v1.0.0 · © {new Date().getFullYear()}
                        </div>
                    ) : null}
                </div>
            </aside>
        </>
    );
}
