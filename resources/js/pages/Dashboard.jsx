import { CalendarDays } from 'lucide-react';
import KpiCards from '../components/dashboard/KpiCards';
import OrdersChart from '../components/dashboard/OrdersChart';
import StatusDonut from '../components/dashboard/StatusDonut';
import TopDrivers from '../components/dashboard/TopDrivers';
import RecentOrders from '../components/dashboard/RecentOrders';
import RecentActivity from '../components/dashboard/RecentActivity';
import IntegrationsBanner from '../components/dashboard/IntegrationsBanner';
import { useAuth } from '../contexts/AuthContext';

function formatFrenchDate(date = new Date()) {
    return new Intl.DateTimeFormat('fr-FR', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    }).format(date);
}

export default function Dashboard() {
    const { user } = useAuth();
    const todayLabel = formatFrenchDate();
    const capitalized = todayLabel.charAt(0).toUpperCase() + todayLabel.slice(1);
    const firstName = user?.name?.split(/\s+/)[0] || 'Admin';

    return (
        <div className="space-y-4 sm:space-y-6">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                <div>
                    <h1 className="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl lg:text-[28px]">
                        Bonjour {firstName} 👋
                    </h1>
                    <p className="mt-1 text-sm font-medium text-slate-500">
                        Voici un aperçu de vos livraisons aujourd&apos;hui.
                    </p>
                </div>
                <div className="inline-flex max-w-full items-center gap-2.5 self-start rounded-xl border border-slate-200 bg-white px-3 py-2 shadow-sm shadow-slate-200/40 sm:px-3.5 sm:py-2.5">
                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600">
                        <CalendarDays className="h-[18px] w-[18px]" />
                    </div>
                    <div className="min-w-0">
                        <div className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                            Aujourd&apos;hui
                        </div>
                        <div className="truncate text-sm font-semibold text-slate-800">{capitalized}</div>
                    </div>
                </div>
            </div>

            <KpiCards />

            <div className="grid gap-4 lg:grid-cols-2 xl:grid-cols-12">
                <div className="lg:col-span-2 xl:col-span-6">
                    <OrdersChart />
                </div>
                <div className="xl:col-span-3">
                    <StatusDonut />
                </div>
                <div className="xl:col-span-3">
                    <TopDrivers />
                </div>
            </div>

            <div className="grid gap-4 xl:grid-cols-12">
                <div className="min-w-0 xl:col-span-8">
                    <RecentOrders />
                </div>
                <div className="xl:col-span-4">
                    <RecentActivity />
                </div>
            </div>

            <IntegrationsBanner />
        </div>
    );
}
