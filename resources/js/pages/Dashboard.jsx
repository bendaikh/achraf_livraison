import { useCallback, useEffect, useState } from 'react';
import { CalendarDays, ClipboardList, PackagePlus, Plus, Warehouse } from 'lucide-react';
import api, { errorMessage } from '../lib/api';
import { useMeta } from '../context/MetaContext';
import { formatDate } from '../lib/format';
import { Alert, Button, Spinner } from '../components/ui';
import DashboardFilters, { DEFAULT_FILTERS } from '../components/dashboard/DashboardFilters';
import StatCards from '../components/dashboard/StatCards';
import RatesCards from '../components/dashboard/RatesCards';
import MissionsToday from '../components/dashboard/MissionsToday';
import CashSummary from '../components/dashboard/CashSummary';
import AlertsPanel from '../components/dashboard/AlertsPanel';
import DriverActivity from '../components/dashboard/DriverActivity';
import RecentOrders from '../components/dashboard/RecentOrders';
import StatusBreakdown from '../components/dashboard/StatusBreakdown';
import IntegrationsBanner from '../components/dashboard/IntegrationsBanner';
import MissionDrawer from '../components/missions/MissionDrawer';

const REFRESH_MS = 60000;

export default function Dashboard() {
    const meta = useMeta();
    const [filters, setFilters] = useState(DEFAULT_FILTERS);
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [creating, setCreating] = useState(null);
    const [flash, setFlash] = useState(null);

    const load = useCallback(async () => {
        if (filters.period === 'custom' && !filters.from) return;
        try {
            const params = { period: filters.period, driver_id: filters.driver_id || undefined };
            if (filters.period === 'custom') Object.assign(params, { from: filters.from, to: filters.to || filters.from });
            const { data: res } = await api.get('/dashboard', { params });
            setData(res.data);
            setError(null);
        } catch (e) {
            setError(errorMessage(e));
        }
    }, [filters]);

    useEffect(() => {
        load();
        const t = setInterval(load, REFRESH_MS); // stays up to date automatically
        return () => clearInterval(t);
    }, [load]);

    const p = data?.period;
    const periodText = p ? (p.from === p.to ? formatDate(p.from) : `${formatDate(p.from)} → ${formatDate(p.to)}`) : '';

    return (
        <div className="space-y-3 sm:space-y-4">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">Bonjour {meta.currentUser?.name || ''} 👋</h1>
                    <p className="mt-0.5 flex items-center gap-1.5 text-sm font-medium text-slate-500">
                        <CalendarDays className="h-4 w-4" /> {p?.label} · {periodText}
                    </p>
                </div>
                <div className="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:justify-end">
                    <Button onClick={() => setCreating('ramassage')}>
                        <PackagePlus className="h-4 w-4" /> Créer un ramassage
                    </Button>
                    <Button onClick={() => setCreating('depot_partenaire')}>
                        <Warehouse className="h-4 w-4" /> Créer un dépôt partenaire
                    </Button>
                    <Button variant="secondary" disabled title="Bientôt disponible">
                        <Plus className="h-4 w-4" /> Créer une mission
                    </Button>
                    <Button variant="secondary" disabled title="Bientôt disponible">
                        <ClipboardList className="h-4 w-4" /> Affecter une commande
                    </Button>
                </div>
            </div>

            <DashboardFilters filters={filters} onChange={setFilters} />
            <Alert>{error}</Alert>
            <Alert type="success">{flash}</Alert>

            {!data ? (
                <Spinner />
            ) : (
                <>
                    <StatCards cards={data.cards} />
                    <RatesCards rates={data.rates} />

                    <div className="grid gap-3 sm:gap-4 lg:grid-cols-3">
                        <MissionsToday missions={data.missions} period={data.period} driverId={filters.driver_id} />
                        <CashSummary cash={data.cash} />
                        <AlertsPanel alerts={data.alerts} />
                    </div>

                    <DriverActivity drivers={data.drivers} />

                    <div className="grid gap-3 sm:gap-4 lg:grid-cols-3">
                        <div className="min-w-0 lg:col-span-2">
                            <RecentOrders orders={data.recent_orders} period={data.period} />
                        </div>
                        <StatusBreakdown items={data.status_breakdown} />
                    </div>

                    <IntegrationsBanner />
                </>
            )}

            <MissionDrawer
                type={creating}
                open={!!creating}
                onClose={() => setCreating(null)}
                onCreated={(m) => {
                    setCreating(null);
                    setFlash(`Mission ${m.reference} créée${m.driver ? ` et attribuée à ${m.driver.name}` : ''}.`);
                    load();
                }}
            />
        </div>
    );
}
