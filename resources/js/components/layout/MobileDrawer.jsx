import { useRef } from 'react';
import { NavLink, useLocation } from 'react-router-dom';
import { X } from 'lucide-react';
import { getActiveModule, modulesForUser } from '../../navigation';
import { useSidebar } from './SidebarContext';
import { useAuth } from '../../contexts/AuthContext';
import { useWhatsAppUnread } from '../../contexts/WhatsAppUnreadContext';
import BrandLogo, { BrandIcon } from './BrandLogo';

function DrawerItem({ mod, active, badge, onNavigate }) {
    const Icon = mod.icon;

    return (
        <NavLink
            to={mod.to}
            onClick={onNavigate}
            className={[
                'flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold transition-colors',
                active ? 'bg-blue-50 text-blue-700' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900',
            ].join(' ')}
        >
            <Icon className="h-[18px] w-[18px] shrink-0" strokeWidth={2} />
            <span className="flex-1 truncate">{mod.label}</span>
            {badge > 0 ? (
                <span className="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-emerald-500 px-1.5 text-[11px] font-semibold text-white">
                    {badge > 99 ? '99+' : badge}
                </span>
            ) : null}
        </NavLink>
    );
}

export default function MobileDrawer() {
    const { mobileOpen, closeMobile } = useSidebar();
    const { user } = useAuth();
    const { unreadCount } = useWhatsAppUnread();
    const location = useLocation();
    const activeId = getActiveModule(location.pathname, user)?.id;
    const touch = useRef(null);

    function onTouchStart(event) {
        const point = event.touches[0];
        touch.current = { x: point.clientX, y: point.clientY };
    }

    function onTouchEnd(event) {
        if (!touch.current) return;
        const point = event.changedTouches[0];
        const dx = point.clientX - touch.current.x;
        const dy = point.clientY - touch.current.y;
        touch.current = null;
        if (dx < -60 && Math.abs(dx) > Math.abs(dy)) {
            closeMobile();
        }
    }

    return (
        <div className="lg:hidden">
            <button
                type="button"
                aria-label="Fermer le menu"
                aria-hidden={!mobileOpen}
                tabIndex={-1}
                className={[
                    'fixed inset-0 z-40 bg-slate-900/50 transition-opacity',
                    mobileOpen ? 'opacity-100' : 'pointer-events-none opacity-0',
                ].join(' ')}
                onClick={closeMobile}
            />

            <aside
                aria-hidden={!mobileOpen}
                inert={!mobileOpen ? true : undefined}
                onTouchStart={onTouchStart}
                onTouchEnd={onTouchEnd}
                className={[
                    'fixed inset-y-0 left-0 z-50 flex w-[min(88vw,340px)] flex-col bg-white shadow-2xl shadow-slate-900/20 transition-transform duration-300 ease-out',
                    mobileOpen ? 'translate-x-0' : 'pointer-events-none -translate-x-full',
                ].join(' ')}
            >
                <div className="flex items-center gap-3 px-4 pb-2 pt-5">
                    <BrandIcon className="h-10 w-10" />
                    <div className="min-w-0 flex-1">
                        <BrandLogo tone="light" badge={user?.is_livreur ? 'Espace livreur' : null} />
                    </div>
                    <button
                        type="button"
                        onClick={closeMobile}
                        className="inline-flex h-9 w-9 items-center justify-center rounded-xl text-slate-500 hover:bg-slate-100"
                        aria-label="Fermer"
                    >
                        <X className="h-4 w-4" />
                    </button>
                </div>

                <nav className="mt-3 flex-1 space-y-0.5 overflow-y-auto px-3 pb-6">
                    {modulesForUser(user).map((mod) => (
                        <DrawerItem
                            key={mod.id}
                            mod={mod}
                            active={activeId === mod.id}
                            badge={mod.badgeKey === 'whatsapp' ? unreadCount : Number(mod.badge) || 0}
                            onNavigate={closeMobile}
                        />
                    ))}
                </nav>
            </aside>
        </div>
    );
}
