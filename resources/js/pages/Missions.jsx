import { useCallback, useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { RotateCcw } from 'lucide-react';
import api, { errorMessage } from '../lib/api';
import { useMeta } from '../context/MetaContext';
import { formatDH, formatDate } from '../lib/format';
import { Alert, Button, Card, EmptyState, Input, PageHeader, Select, Spinner } from '../components/ui';
import { ColorBadge } from '../components/ui/Badge';
/* MISSION_IMPORTS */

const FILTER_KEYS = ['type', 'status', 'driver_id', 'date_from', 'date_to', 'q'];

export default function Missions() {
    const meta = useMeta();
    const [params, setParams] = useSearchParams();
    const [missions, setMissions] = useState(null);
    const [total, setTotal] = useState(0);
    const [error, setError] = useState(null);
    /* MISSION_STATE */

    const filters = Object.fromEntries(FILTER_KEYS.map((k) => [k, params.get(k) || '']));

    const load = useCallback(async () => {
        setError(null);
        try {
            const query = Object.fromEntries(FILTER_KEYS.map((k) => [k, params.get(k)]).filter(([, v]) => v));
            const { data } = await api.get('/missions', { params: { ...query, per_page: 100 } });
            setMissions(data.data);
            setTotal(data.meta?.total ?? data.data.length);
        } catch (e) {
            setError(errorMessage(e));
        }
    }, [params]);

    useEffect(() => {
        load();
    }, [load]);

    function setFilter(key, value) {
        const next = new URLSearchParams(params);
        if (value) next.set(key, value);
        else next.delete(key);
        setParams(next, { replace: true });
    }

    async function changeStatus(mission, status) {
        try {
            await api.post(`/missions/${mission.id}/status`, { status });
            load();
        } catch (e) {
            setError(errorMessage(e));
        }
    }

    const driverName = meta.drivers.find((d) => String(d.id) === filters.driver_id)?.name;

    return (
        <div className="space-y-4">
            <PageHeader
                title={driverName ? `Missions de ${driverName}` : 'Missions'}
                subtitle="Livraisons, ramassages, dépôts partenaires, retours et échanges."
                actions={null /* MISSION_ACTIONS */}
            />

            <Card bodyClassName="p-3 sm:p-4">
                <div className="grid grid-cols-2 gap-2 md:grid-cols-3 xl:grid-cols-6">
                    <Input placeholder="Rechercher…" value={filters.q} onChange={(e) => setFilter('q', e.target.value)} className="col-span-2 md:col-span-1" />
                    <Select value={filters.type} onChange={(e) => setFilter('type', e.target.value)}>
                        <option value="">Tous les types</option>
                        {meta.missionTypes.map((t) => (
                            <option key={t.value} value={t.value}>
                                {t.label}
                            </option>
                        ))}
                        <option value="retour,echange">Retours / échanges</option>
                    </Select>
                    <Select value={filters.status} onChange={(e) => setFilter('status', e.target.value)}>
                        <option value="">Tous les statuts</option>
                        <option value="open">À faire / en cours</option>
                        {meta.missionStatuses.map((s) => (
                            <option key={s.value} value={s.value}>
                                {s.label}
                            </option>
                        ))}
                    </Select>
                    <Select value={filters.driver_id} onChange={(e) => setFilter('driver_id', e.target.value)}>
                        <option value="">Tous les livreurs</option>
                        {meta.drivers.map((d) => (
                            <option key={d.id} value={d.id}>
                                {d.name}
                            </option>
                        ))}
                    </Select>
                    <Input type="date" value={filters.date_from} onChange={(e) => setFilter('date_from', e.target.value)} title="Du" />
                    <Input type="date" value={filters.date_to} onChange={(e) => setFilter('date_to', e.target.value)} title="Au" />
                </div>
                <div className="mt-2 flex items-center justify-between text-xs font-medium text-slate-500">
                    <span>{total} mission(s)</span>
                    <Button size="sm" variant="ghost" onClick={() => setParams({}, { replace: true })}>
                        <RotateCcw className="h-3.5 w-3.5" /> Réinitialiser les filtres
                    </Button>
                </div>
            </Card>

            <Alert>{error}</Alert>

            {!missions ? (
                <Spinner />
            ) : missions.length === 0 ? (
                <Card>
                    <EmptyState>Aucune mission</EmptyState>
                </Card>
            ) : (
                <>
                    {/* Mobile cards */}
                    <div className="space-y-2 md:hidden">
                        {missions.map((m) => (
                            <article key={m.id} className="rounded-2xl border border-slate-200/80 bg-white p-3 shadow-sm">
                                <div className="flex items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <div className="text-sm font-bold text-slate-900">
                                            {m.reference} · {meta.missionTypeMap[m.type]?.label}
                                        </div>
                                        <div className="truncate text-xs text-slate-500">
                                            {m.contact_name} · {m.city || '—'}
                                        </div>
                                    </div>
                                    <ColorBadge color={meta.missionStatusMap[m.status]?.color} label={meta.missionStatusMap[m.status]?.label} />
                                </div>
                                <div className="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500">
                                    <span>
                                        {m.driver?.name || 'Sans livreur'} · {formatDate(m.scheduled_date)} {m.time_slot || ''}
                                    </span>
                                    {m.driver_price !== null && m.driver_price !== undefined ? (
                                        <span className="font-semibold text-slate-700">Tarif : {formatDH(m.driver_price)}</span>
                                    ) : null}
                                </div>
                                <Select value={m.status} onChange={(e) => changeStatus(m, e.target.value)} className="mt-2 h-9 text-xs">
                                    {meta.missionStatuses.map((s) => (
                                        <option key={s.value} value={s.value}>
                                            {s.label}
                                        </option>
                                    ))}
                                </Select>
                            </article>
                        ))}
                    </div>

                    {/* Desktop table */}
                    <Card bodyClassName="p-0" className="hidden md:block">
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-left text-sm">
                                <thead>
                                    <tr className="border-b border-slate-100 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                                        <th className="px-4 py-3">Mission</th>
                                        <th className="px-3 py-3">Type</th>
                                        <th className="px-3 py-3">Contact / destination</th>
                                        <th className="px-3 py-3">Ville</th>
                                        <th className="px-3 py-3">Date</th>
                                        <th className="px-3 py-3">Livreur</th>
                                        <th className="px-3 py-3">Tarif livreur</th>
                                        <th className="px-3 py-3">Montant</th>
                                        <th className="px-4 py-3">Statut</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {missions.map((m) => (
                                        <tr key={m.id} className="border-b border-slate-50 last:border-0 hover:bg-slate-50/70">
                                            <td className="whitespace-nowrap px-4 py-3 font-semibold text-slate-800">
                                                {m.reference}
                                                {m.order_id ? (
                                                    <Link to={`/commandes/${m.order_id}`} className="block text-xs font-medium text-blue-600">
                                                        {m.order_reference}
                                                    </Link>
                                                ) : null}
                                            </td>
                                            <td className="whitespace-nowrap px-3 py-3 text-slate-600">{meta.missionTypeMap[m.type]?.label}</td>
                                            <td className="px-3 py-3">
                                                <div className="font-medium text-slate-700">{m.contact_name}</div>
                                                {m.items_description ? (
                                                    <div className="max-w-[220px] truncate text-xs text-slate-400">
                                                        {m.quantity} × {m.items_description}
                                                    </div>
                                                ) : null}
                                            </td>
                                            <td className="whitespace-nowrap px-3 py-3 text-slate-500">{m.city || '—'}</td>
                                            <td className="whitespace-nowrap px-3 py-3 text-slate-500">
                                                {formatDate(m.scheduled_date)}
                                                {m.time_slot ? <div className="text-xs">{m.time_slot}</div> : null}
                                            </td>
                                            <td className="whitespace-nowrap px-3 py-3 text-slate-600">{m.driver?.name || '—'}</td>
                                            <td className="whitespace-nowrap px-3 py-3 font-semibold text-slate-800">
                                                {m.driver_price !== null && m.driver_price !== undefined ? formatDH(m.driver_price) : '—'}
                                            </td>
                                            <td className="whitespace-nowrap px-3 py-3 text-slate-600">
                                                {m.cash_amount ? `${formatDH(m.cash_amount)} (${m.cash_direction === 'remit' ? 'à remettre' : 'à récupérer'})` : '—'}
                                            </td>
                                            <td className="whitespace-nowrap px-4 py-3">
                                                <Select value={m.status} onChange={(e) => changeStatus(m, e.target.value)} className="h-8 w-36 text-xs">
                                                    {meta.missionStatuses.map((s) => (
                                                        <option key={s.value} value={s.value}>
                                                            {s.label}
                                                        </option>
                                                    ))}
                                                </Select>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </Card>
                </>
            )}
            {/* MISSION_DRAWERS */}
        </div>
    );
}
