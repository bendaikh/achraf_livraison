import { NavLink, useLocation } from 'react-router-dom';
import { modulesForUser, quickAccess } from '../../navigation';
import { useSidebar } from './SidebarContext';
import { useAuth } from '../../contexts/AuthContext';

export default function BottomNav() {
    const location = useLocation();
    const { closeMobile } = useSidebar();
    const { user } = useAuth();
    const allowed = new Set(modulesForUser(user).map((mod) => mod.id));
    const items = quickAccess.filter((item) => allowed.has(item.id));

    if (items.length === 0) return null;

    return (
        <nav
            aria-label="Accès principaux"
            className="fixed inset-x-0 bottom-0 z-30 border-t border-slate-200 bg-white/95 backdrop-blur-md lg:hidden"
            style={{ paddingBottom: 'env(safe-area-inset-bottom)' }}
        >
            <ul className="grid" style={{ gridTemplateColumns: `repeat(${items.length}, minmax(0, 1fr))` }}>
                {items.map((item) => {
                    const Icon = item.icon;
                    const active = item.end
                        ? location.pathname === item.to
                        : location.pathname === item.to || location.pathname.startsWith(`${item.to}/`);

                    return (
                        <li key={item.id}>
                            <NavLink
                                to={item.to}
                                end={item.end ?? false}
                                onClick={closeMobile}
                                className={[
                                    'flex flex-col items-center justify-center gap-1 px-1 py-2 text-[10px] font-semibold leading-none',
                                    active ? 'text-blue-600' : 'text-slate-400',
                                ].join(' ')}
                            >
                                <Icon className="h-5 w-5" strokeWidth={active ? 2.4 : 2} />
                                <span className="truncate">{item.label}</span>
                            </NavLink>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}
