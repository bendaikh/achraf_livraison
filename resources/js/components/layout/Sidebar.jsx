import { NavLink, useLocation } from 'react-router-dom';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { modulesForUser, getActiveModule } from '../../navigation';
import { useSidebar } from './SidebarContext';
import { useAuth } from '../../contexts/AuthContext';
import { useWhatsAppUnread } from '../../contexts/WhatsAppUnreadContext';
import BrandLogo, { BrandIcon } from './BrandLogo';
import { BRAND } from '../../lib/brand';

function ModuleItem({ mod, collapsed, onNavigate }) {
    const location = useLocation();
    const { user } = useAuth();
    const { unreadCount } = useWhatsAppUnread();
    const active = getActiveModule(location.pathname, user)?.id === mod.id;
    const Icon = mod.icon;
    const badge =
        mod.badgeKey === 'whatsapp' ? unreadCount : mod.badge ? Number(mod.badge) : 0;

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
            {!collapsed && badge > 0 ? (
                <span className="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-emerald-500 px-1.5 text-[11px] font-semibold text-white">
                    {badge > 99 ? '99+' : badge}
                </span>
            ) : null}
            {collapsed && badge > 0 ? (
                <span className="absolute right-1.5 top-1.5 h-2 w-2 rounded-full bg-emerald-500" />
            ) : null}
        </NavLink>
    );
}

export default function Sidebar() {
    const { collapsed, closeMobile, toggleCollapsed } = useSidebar();
    const { user } = useAuth();
    const compact = collapsed;
    const navModules = modulesForUser(user);

    return (
        <>
            <aside
                className={[
                    'fixed inset-y-0 left-0 z-50 hidden flex-col bg-[#1e293b] text-white transition-all duration-300 lg:flex',
                    compact ? 'w-[80px]' : 'w-[260px]',
                ].join(' ')}
            >
                <div
                    className={[
                        'flex items-center gap-3 pb-2 pt-5',
                        compact ? 'justify-center px-2' : 'px-5',
                    ].join(' ')}
                >
                    <BrandIcon />
                    {!compact ? (
                        <div className="min-w-0 flex-1">
                            <BrandLogo badge={user?.is_livreur ? 'Espace livreur' : null} />
                        </div>
                    ) : null}
                </div>

                <nav className="mt-4 flex-1 space-y-1 overflow-y-auto px-3 pb-4">
                    {navModules.map((mod) => (
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
                            {BRAND.title} v{BRAND.version} · © {new Date().getFullYear()}
                        </div>
                    ) : null}
                </div>
            </aside>
        </>
    );
}
