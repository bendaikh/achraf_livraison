import { useEffect } from 'react';
import { Outlet, useLocation } from 'react-router-dom';
import { getActiveModule } from '../../navigation';
import { useAuth } from '../../contexts/AuthContext';
import { pageTitle } from '../../lib/brand';
import Sidebar from './Sidebar';
import Header from './Header';
import BodyNav from './BodyNav';
import { SidebarProvider, useSidebar } from './SidebarContext';
import { WhatsAppUnreadProvider } from '../../contexts/WhatsAppUnreadContext';

function LayoutShell() {
    const { collapsed } = useSidebar();
    const location = useLocation();
    const { user } = useAuth();

    // Browser tab: "<Module> · Lav'Fast Flow"
    useEffect(() => {
        document.title = pageTitle(getActiveModule(location.pathname, user)?.label);
    }, [location.pathname, user]);

    return (
        <div className="min-h-screen bg-[#f1f5f9]">
            <Sidebar />
            <div
                className={[
                    'min-w-0 transition-[padding] duration-300',
                    collapsed ? 'lg:pl-[80px]' : 'lg:pl-[260px]',
                ].join(' ')}
            >
                <Header />
                <BodyNav />
                <main className="px-3 py-4 sm:px-6 sm:py-6">
                    <Outlet />
                </main>
            </div>
        </div>
    );
}

export default function AppLayout() {
    return (
        <SidebarProvider>
            <WhatsAppUnreadProvider>
                <LayoutShell />
            </WhatsAppUnreadProvider>
        </SidebarProvider>
    );
}
