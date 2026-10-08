import { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { PackageSearch, Play, RefreshCw, Search } from 'lucide-react';
import ConfirmationOrderCard from './ConfirmationOrderCard';
import ConfirmationStats from '../components/confirmation/ConfirmationStats';
import { useMeta } from '../context/MetaContext';

export default function Confirmation() {
    const [orders, setOrders] = useState([]);
    const [counts, setCounts] = useState({});
    const [statuses, setStatuses] = useState([]);
    const [tabs, setTabs] = useState([]);
    const [recall, setRecall] = useState(null);
    const [bucket, setBucket] = useState(params.get('bucket') || '');
    const [othersOpen, setOthersOpen] = useState(false);
    const [othersQuery, setOthersQuery] = useState('');
    const [meta, setMeta] = useState({ current_page: 1, last_page: 1, per_page: 25, total: 0 });
    const navigate = useNavigate();
    const [params] = useSearchParams();
    const [search, setSearch] = useState(params.get('search') || '');
    const [debouncedSearch, setDebouncedSearch] = useState(params.get('search') || '');
    const [filter, setFilter] = useState(params.get('filter') || '');
    const [agent, setAgent] = useState(params.get('agent') || '');
    const metaCtx = useMeta();
    const [page, setPage] = useState(1);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [toast, setToast] = useState('');

    useEffect(() => {
        const timer = setTimeout(() => setDebouncedSearch(search.trim()), 300);
        return () => clearTimeout(timer);
    }, [search]);

    useEffect(() => {
        setPage(1);
    }, [debouncedSearch, filter, agent, bucket]);

    const load = useCallback(async () => {
        setLoading(true);
        setError('');
        try {
            const { data } = await window.axios.get('/api/confirmation/orders', {
                params: {
                    page,
                    per_page: 25,
                    search: debouncedSearch || undefined,
                    filter: filter || undefined,
                    agent: agent || undefined,
                    bucket: bucket || undefined,
                },
            });
            setOrders(data.orders || []);
            setCounts(data.counts || {});
            setStatuses(data.statuses || []);
            setTabs(data.tabs || []);
            setRecall(data.recall || null);
            setMeta(data.meta || { current_page: 1, last_page: 1, per_page: 25, total: 0 });

            const nextFilter = data.meta?.filter || data.meta?.default_filter || data.statuses?.[0]?.code;
            if (nextFilter && !filter) {
                setFilter(nextFilter);
            }
        } catch (err) {
            setError(err.response?.data?.message || 'Impossible de charger la file de confirmation.');
            setOrders([]);
        } finally {
            setLoading(false);
        }
    }, [page, debouncedSearch, filter, agent, bucket]);

    useEffect(() => {
        load();
    }, [load]);

    /** T5 — each order opens in the full-page Centre de confirmation (same queue filter/search). */
    const queueQs = () => {
        const q = new URLSearchParams();
        if (filter) q.set('filter', filter);
        if (bucket) q.set('bucket', bucket);
        if (debouncedSearch) q.set('search', debouncedSearch);
        if (agent) q.set('agent', agent);
        const str = q.toString();
        return str ? `?${str}` : '';
    };
    const openOrder = (order) => navigate(`/confirmation/${order.id}${queueQs()}`);
    const startQueue = () => {
        window.localStorage.setItem('lavfast:confirmation-auto-next', '1');
        if (orders[0]) openOrder(orders[0]);
    };

    const mainTabs = tabs.length ? tabs : statuses.filter((item) => item.show_in_filters);
    const filterButtons = useMemo(
        () =>
            mainTabs.map((item) => ({
                value: item.code,
                label: item.filter_label || item.name,
                count: counts[item.code] ?? 0,
            })),
        [mainTabs, counts],
    );
    const otherStatuses = statuses.filter((item) => !mainTabs.some((tab) => tab.code === item.code));
    const visibleOthers = otherStatuses.filter((item) =>
        `${item.name} ${item.filter_label || ''}`.toLowerCase().includes(othersQuery.trim().toLowerCase()),
    );

    return (
        <div className="space-y-4 sm:space-y-6">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">Confirmation</h1>
                    <p className="mt-1 text-sm font-medium text-slate-500">
                        File de travail pour confirmer les commandes Shopify avec les clients.
                    </p>
                </div>
                <div className="flex gap-2 self-start">
                <button
                    type="button"
                    onClick={startQueue}
                    disabled={!orders.length}
                    className="inline-flex h-10 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-bold text-white shadow-sm shadow-blue-500/20 transition hover:bg-blue-700 disabled:opacity-50"
                >
                    <Play className="h-4 w-4" />
                    Démarrer la file
                </button>
                <button
                    type="button"
                    onClick={load}
                    disabled={loading}
                    className="inline-flex h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50 disabled:opacity-60"
                >
                    <RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />
                    Actualiser
                </button>
                </div>
            </div>

            <ConfirmationStats />

            {toast ? (
                <div className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">
                    {toast}
                </div>
            ) : null}

            <section className="space-y-3">
                <div className="rounded-2xl border border-slate-200/80 bg-white p-3 shadow-sm shadow-slate-200/40 sm:p-4">
                    <div className="flex flex-col gap-2 sm:flex-row">
                    <div className="relative flex-1">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            type="search"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="N° commande, client, téléphone…"
                            className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-9 pr-3 text-sm outline-none transition focus:border-blue-300 focus:bg-white focus:ring-4 focus:ring-blue-500/10"
                        />
                    </div>
                    <select
                        value={agent}
                        onChange={(e) => setAgent(e.target.value)}
                        aria-label="Agent"
                        className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm font-semibold text-slate-700 outline-none focus:border-blue-300 sm:w-56"
                    >
                        <option value="">Tous les agents</option>
                        <option value="me">Mes commandes</option>
                        <option value="none">Non assignées</option>
                        {(metaCtx.users || []).map((u) => (
                            <option key={u.id} value={u.id}>
                                {u.name}
                            </option>
                        ))}
                    </select>
                    </div>
                    <div className="mt-3 flex flex-wrap items-center gap-1.5">
                        {filterButtons.map((item) => {
                            const active = filter === item.value;
                            return (
                                <button
                                    key={item.value}
                                    type="button"
                                    onClick={() => setFilter(item.value)}
                                    className={`rounded-lg px-3 py-1.5 text-xs font-semibold transition ${
                                        active
                                            ? 'bg-blue-600 text-white shadow-sm shadow-blue-500/20'
                                            : 'bg-slate-100 text-slate-600 hover:bg-slate-200/80'
                                    }`}
                                >
                                    {item.label}
                                    <span className={`ml-1.5 ${active ? 'text-blue-100' : 'text-slate-400'}`}>
                                        {item.count}
                                    </span>
                                </button>
                            );
                        })}
                        {otherStatuses.length ? (
                            <div className="relative">
                                <button
                                    type="button"
                                    onClick={() => setOthersOpen((v) => !v)}
                                    className={`rounded-lg px-3 py-1.5 text-xs font-semibold ${otherStatuses.some((item) => item.code === filter) ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600'}`}
                                >
                                    Autres statuts ▾
                                </button>
                                {othersOpen ? (
                                    <div className="absolute left-0 z-20 mt-1 w-64 rounded-xl border border-slate-200 bg-white p-2 shadow-lg">
                                        <input
                                            value={othersQuery}
                                            onChange={(e) => setOthersQuery(e.target.value)}
                                            placeholder="Rechercher un statut…"
                                            className="mb-2 h-8 w-full rounded-lg border border-slate-200 px-2 text-xs"
                                        />
                                        <div className="max-h-56 overflow-y-auto">
                                            {visibleOthers.map((item) => (
                                                <button
                                                    key={item.code}
                                                    type="button"
                                                    onClick={() => {
                                                        setFilter(item.code);
                                                        setOthersOpen(false);
                                                    }}
                                                    className="flex w-full items-center justify-between rounded-lg px-2 py-1.5 text-left text-xs font-semibold text-slate-700 hover:bg-slate-50"
                                                >
                                                    <span>{item.name}</span>
                                                    <span className="text-slate-400">{counts[item.code] ?? 0}</span>
                                                </button>
                                            ))}
                                            {!visibleOthers.length ? <p className="px-2 py-1 text-xs text-slate-400">Aucun statut</p> : null}
                                        </div>
                                    </div>
                                ) : null}
                            </div>
                        ) : null}
                    </div>
                    {recall ? (
                        <div className="mt-2 flex flex-wrap gap-1.5">
                            {[
                                ['overdue', 'En retard', recall.overdue],
                                ['today', 'Aujourd’hui', recall.today],
                                ['upcoming', 'À venir', recall.upcoming],
                            ].map(([key, label, count]) => (
                                <button
                                    key={key}
                                    type="button"
                                    onClick={() => setBucket(bucket === key ? '' : key)}
                                    className={`rounded-full px-2.5 py-1 text-[11px] font-bold ${bucket === key ? 'bg-amber-500 text-white' : 'bg-amber-50 text-amber-800'}`}
                                >
                                    {label} ({count ?? 0})
                                </button>
                            ))}
                        </div>
                    ) : null}
                </div>

                {error ? (
                    <div className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700">
                        {error}
                    </div>
                ) : null}

                {loading && orders.length === 0 ? (
                    <div className="flex min-h-[240px] items-center justify-center rounded-2xl border border-slate-200/80 bg-white text-sm font-medium text-slate-500">
                        Chargement…
                    </div>
                ) : orders.length === 0 ? (
                    <div className="flex min-h-[280px] flex-col items-center justify-center rounded-2xl border border-slate-200/80 bg-white px-6 py-12 text-center shadow-sm">
                        <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-500">
                            <PackageSearch className="h-6 w-6" />
                        </div>
                        <p className="mt-4 text-sm font-semibold text-slate-800">Aucune commande dans ce filtre</p>
                        <p className="mt-1 max-w-sm text-sm font-medium text-slate-500">
                            Les nouvelles commandes Shopify apparaissent ici dans le statut par défaut.
                        </p>
                    </div>
                ) : (
                    <div className="grid gap-3">
                        {orders.map((order) => (
                            <ConfirmationOrderCard key={order.id} order={order} onOpen={openOrder} />
                        ))}
                    </div>
                )}

                {meta.total > 0 ? (
                    <div className="flex flex-col gap-3 rounded-2xl border border-slate-200/80 bg-white px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-xs font-medium text-slate-500">
                            {meta.total} commande{meta.total > 1 ? 's' : ''} · page {meta.current_page} /{' '}
                            {meta.last_page}
                        </p>
                        <div className="flex gap-2">
                            <button
                                type="button"
                                disabled={page <= 1 || loading}
                                onClick={() => setPage((p) => Math.max(1, p - 1))}
                                className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-40"
                            >
                                Précédent
                            </button>
                            <button
                                type="button"
                                disabled={page >= meta.last_page || loading}
                                onClick={() => setPage((p) => p + 1)}
                                className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-40"
                            >
                                Suivant
                            </button>
                        </div>
                    </div>
                ) : null}
            </section>

        </div>
    );
}
