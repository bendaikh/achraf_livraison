import { NavLink, useLocation } from 'react-router-dom';
import { ChevronsLeft, ChevronsRight } from 'lucide-react';
import { getActiveModule } from '../../navigation';
import { useSidebar } from './SidebarContext';

export default function BodyNav() {
    const location = useLocation();
    const module = getActiveModule(location.pathname);
    const { collapsed, toggleCollapsed } = useSidebar();

    if (!module) return null;

    return (
        <div className="border-b border-slate-200 bg-white">
            <div className="flex items-center gap-2 px-3 pt-3 sm:px-6">
                <button
                    type="button"
                    onClick={toggleCollapsed}
                    className="hidden h-8 w-8 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-slate-800 lg:inline-flex"
                    aria-label={collapsed ? 'Développer le menu' : 'Réduire le menu'}
                >
                    {collapsed ? (
                        <ChevronsRight className="h-4 w-4" />
                    ) : (
                        <ChevronsLeft className="h-4 w-4" />
                    )}
                </button>
                <h2 className="text-sm font-bold text-slate-800 sm:text-base">{module.label}</h2>
            </div>

            <nav className="mt-2 flex gap-1 overflow-x-auto px-3 sm:px-6">
                {module.tabs.map((tab) => (
                    <NavLink
                        key={tab.to}
                        to={tab.to}
                        end={tab.end ?? false}
                        className={({ isActive }) =>
                            [
                                'shrink-0 border-b-2 px-3 py-2.5 text-sm font-semibold transition-colors whitespace-nowrap',
                                isActive
                                    ? 'border-blue-600 text-blue-700'
                                    : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-800',
                            ].join(' ')
                        }
                    >
                        {tab.label}
                    </NavLink>
                ))}
            </nav>
        </div>
    );
}
