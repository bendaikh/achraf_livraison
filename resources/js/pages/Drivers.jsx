import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Mail, Pencil, Phone, Plus, RefreshCw, Search, ListChecks, Wallet } from 'lucide-react';
import api, { errorMessage } from '../lib/api';
import { useMeta } from '../context/MetaContext';
import { formatDH, initials } from '../lib/format';
import { Alert, Button, EmptyState, Input, PageHeader, Spinner } from '../components/ui';
import DriverForm from '../components/drivers/DriverForm';

const COLORS = ['#2563eb', '#0ea5e9', '#8b5cf6', '#14b8a6', '#f59e0b', '#ec4899'];

export default function Drivers() {
    const meta = useMeta();
    const [drivers, setDrivers] = useState(null);
    const [q, setQ] = useState('');
    const [activeFilter, setActiveFilter] = useState('all');
    const [error, setError] = useState(null);
    const [editing, setEditing] = useState(null); // null closed, {} new, driver object edit
    const [focusTariffs, setFocusTariffs] = useState(false);

    const load = useCallback(async () => {
        try {
            const { data } = await api.get('/drivers', {
                params: { q: q || undefined, active: activeFilter === 'all' ? undefined : activeFilter === 'active' ? '1' : '0' },
            });
            setDrivers(data.data);
        } catch (e) {
            setError(errorMessage(e));
        }
    }, [q, activeFilter]);

    useEffect(() => {
        const t = setTimeout(load, 250);
        return () => clearTimeout(t);
    }, [load]);

    return (
        <div className="space-y-4">
            <PageHeader
                title="Livreurs"
                subtitle="Équipe locale Lavfast — accès connexion, missions, COD détenu et tarifs."
                actions={
                    <>
                        <Button variant="secondary" onClick={load}>
                            <RefreshCw className="h-4 w-4" /> Actualiser
                        </Button>
                        <Button onClick={() => { setFocusTariffs(false); setEditing({}); }}>
                            <Plus className="h-4 w-4" /> Ajouter un livreur
                        </Button>
                    </>
                }
            />
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                <div className="relative w-full max-w-md">
                    <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Nom, téléphone, email, ville…" className="pl-9" />
                </div>
                <div className="flex gap-1.5">
                    {[
                        { value: 'all', label: 'Tous' },
                        { value: 'active', label: 'Actifs' },
                        { value: 'inactive', label: 'Inactifs' },
                    ].map((item) => (
                        <button
                            key={item.value}
                            type="button"
                            onClick={() => setActiveFilter(item.value)}
                            className={`rounded-lg px-3 py-1.5 text-xs font-semibold transition ${
                                activeFilter === item.value ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200/80'
                            }`}
                        >
                            {item.label}
                        </button>
                    ))}
                </div>
            </div>
            <Alert>{error}</Alert>

            {!drivers ? (
                <Spinner />
            ) : drivers.length === 0 ? (
                <EmptyState>Aucun livreur — créez un livreur pour lui affecter des commandes confirmées.</EmptyState>
            ) : (
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    {drivers.map((d, i) => (
                        <article key={d.id} className="flex flex-col rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm shadow-slate-200/40">
                            <div className="flex items-start gap-3">
                                <div
                                    className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-sm font-bold text-white"
                                    style={{ backgroundColor: COLORS[i % COLORS.length] }}
                                >
                                    {initials(d.name)}
                                </div>
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <h3 className="truncate font-bold text-slate-900">{d.name}</h3>
                                        <span
                                            className={`rounded-full px-2 py-0.5 text-[10px] font-bold ${d.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}
                                        >
                                            {d.is_active ? 'Actif' : 'Inactif'}
                                        </span>
                                    </div>
                                    <div className="mt-0.5 flex flex-wrap items-center gap-x-3 text-xs text-slate-500">
                                        {d.phone ? (
                                            <span className="inline-flex items-center gap-1">
                                                <Phone className="h-3 w-3" /> {d.phone}
                                            </span>
                                        ) : null}
                                        {d.city ? <span>{d.city}</span> : null}
                                        {d.vehicle ? <span>{d.vehicle}</span> : null}
                                    </div>
                                    {d.email ? (
                                        <div className="mt-0.5 inline-flex items-center gap-1 text-xs text-slate-400">
                                            <Mail className="h-3 w-3" /> {d.email}
                                        </div>
                                    ) : (
                                        <div className="mt-0.5 text-xs font-medium text-amber-600">Sans accès connexion</div>
                                    )}
                                </div>
                            </div>
                            {d.stats ? (
                                <div className="mt-3 grid grid-cols-4 gap-1.5 text-center">
                                    {[
                                        ['Attribuées', d.stats.orders_assigned],
                                        ['En cours', d.stats.orders_in_progress],
                                        ['Livrées', d.stats.orders_delivered],
                                        ['COD détenu', formatDH(d.stats.cod_held)],
                                    ].map(([label, value]) => (
                                        <div key={label} className="rounded-xl bg-slate-50 px-1 py-1.5">
                                            <div className="text-sm font-bold text-slate-900">{value}</div>
                                            <div className="text-[9px] font-semibold uppercase text-slate-400">{label}</div>
                                        </div>
                                    ))}
                                </div>
                            ) : null}
                            <div className="mt-3 rounded-xl bg-slate-50 p-2.5">
                                <div className="mb-1.5 text-[10px] font-bold uppercase tracking-wide text-slate-400">Tarifs des missions</div>
                                <dl className="grid grid-cols-3 gap-x-2 gap-y-1.5 sm:grid-cols-5">
                                    {meta.missionTypes.map((t) => (
                                        <div key={t.value} className="min-w-0">
                                            <dt className="truncate text-[10px] font-medium text-slate-500" title={t.label}>
                                                {t.label}
                                            </dt>
                                            <dd className="text-sm font-bold text-slate-800">{formatDH(d.tariffs?.[t.value])}</dd>
                                        </div>
                                    ))}
                                </dl>
                            </div>
                            <div className="mt-3 flex flex-wrap gap-2 border-t border-slate-100 pt-3">
                                <Button size="sm" variant="secondary" onClick={() => { setFocusTariffs(false); setEditing(d); }}>
                                    <Pencil className="h-3.5 w-3.5" /> Modifier
                                </Button>
                                <Button size="sm" variant="secondary" onClick={() => { setFocusTariffs(true); setEditing(d); }}>
                                    <Wallet className="h-3.5 w-3.5" /> Tarifs
                                </Button>
                                <Link
                                    to={`/missions?driver_id=${d.id}`}
                                    className="inline-flex h-8 items-center gap-1.5 rounded-xl border border-slate-200 px-2.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                                >
                                    <ListChecks className="h-3.5 w-3.5" /> Missions
                                </Link>
                            </div>
                        </article>
                    ))}
                </div>
            )}

            <DriverForm
                open={editing !== null}
                driver={editing && editing.id ? editing : null}
                focusTariffs={focusTariffs}
                onClose={() => setEditing(null)}
                onSaved={() => {
                    setEditing(null);
                    load();
                    meta.reload();
                }}
            />
        </div>
    );
}
