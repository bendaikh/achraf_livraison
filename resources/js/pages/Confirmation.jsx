import { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { PackageSearch, Play, RefreshCw, Search } from 'lucide-react';
import ConfirmationOrderCard from './ConfirmationOrderCard';
import ConfirmationStats from '../components/confirmation/ConfirmationStats';

export default function Confirmation() {
    const [orders, setOrders] = useState([]);
    const [counts, setCounts] = useState({});
    const [statuses, setStatuses] = useState([]);
    const [meta, setMeta] = useState({ current_page: 1, last_page: 1, per_page: 25, total: 0 });
    const navigate = useNavigate();
    const [params] = useSearchParams();
    const [search, setSearch] = useState(params.get('search') || '');
    const [debouncedSearch, setDebouncedSearch] = useState(params.get('search') || '');
    const [filter, setFilter] = useState(params.get('filter') || '');
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
    }, [debouncedSearch, filter]);

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
                },
            });
            setOrders(data.orders || []);
            setCounts(data.counts || {});
            setStatuses(data.statuses || []);
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
    }, [page, debouncedSearch, filter]);

    useEffect(() => {
        load();
    }, [load]);

    /** T5 — each order opens in the full-page Centre de confirmation (same queue filter/search). */
    const queueQs = () => {
        const q = new URLSearchParams();
        if (filter) q.set('filter', filter);
        if (debouncedSearch) q.set('search', debouncedSearch);
        const str = q.toString();
        return str ? `?${str}` : '';
    };
    const openOrder = (order) => navigate(`/confirmation/${order.id}${queueQs()}`);
    const startQueue = () => {
        window.localStorage.setItem('lavfast:confirmation-auto-next', '1');
        if (orders[0]) openOrder(orders[0]);
    };

    const filterButtons = useMemo(
        () =>
            statuses.map((item) => ({
                value: item.code,
                label: item.filter_label || item.name,
                count: counts[item.code] ?? 0,
            })),
        [statuses, counts],
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
                    <div className="relative">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            type="search"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="N° commande, client, téléphone…"
                            className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-9 pr-3 text-sm outline-none transition focus:border-blue-300 focus:bg-white focus:ring-4 focus:ring-blue-500/10"
                        />
                    </div>
                    <div className="mt-3 flex gap-1.5 overflow-x-auto pb-1">
                        {filterButtons.map((item) => {
                            const active = filter === item.value;
                            return (
                                <button
                                    key={item.value}
                                    type="button"
                                    onClick={() => setFilter(item.value)}
                                    className={`shrink-0 rounded-lg px-3 py-1.5 text-xs font-semibold transition ${
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
                    </div>
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
