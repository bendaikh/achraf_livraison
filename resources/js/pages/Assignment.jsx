import { useCallback, useEffect, useMemo, useState } from 'react';
import { Bike, PackageSearch, RefreshCw, Search, X } from 'lucide-react';
import {
    formatDate,
    formatHistoryLine,
    formatMoney,
    orderDisplayName,
    statusBadgeStyle,
} from './confirmationHelpers';

const FILTERS = [
    { value: 'to_assign', label: 'À attribuer' },
    { value: 'assigned', label: 'Attribuées' },
    { value: 'failed', label: 'À retraiter' },
    { value: 'delivered', label: 'Livrées' },
];

function productsLabel(items) {
    if (!Array.isArray(items) || items.length === 0) return '—';
    return items
        .map((item) => {
            const qty = item.quantity || item.qty || 1;
            const title = item.title || item.name || 'Produit';
            return `${qty}× ${title}`;
        })
        .join(', ');
}

function AssignModal({ open, orderIds, drivers, busy, error, onClose, onSubmit }) {
    const [driverId, setDriverId] = useState('');

    useEffect(() => {
        if (open) {
            setDriverId(drivers[0]?.id ? String(drivers[0].id) : '');
        }
    }, [open, drivers]);

    if (!open) return null;

    return (
        <div className="fixed inset-0 z-[70] flex items-end justify-center bg-slate-900/50 p-0 sm:items-center sm:p-4">
            <button type="button" className="absolute inset-0 cursor-default" aria-label="Fermer" onClick={onClose} />
            <div className="relative z-10 w-full max-w-md rounded-t-2xl bg-white p-5 shadow-xl sm:rounded-2xl">
                <div className="mb-4 flex items-center justify-between gap-3">
                    <h3 className="text-base font-bold text-slate-900">Affecter à un livreur</h3>
                    <button
                        type="button"
                        onClick={onClose}
                        className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100"
                    >
                        <X className="h-4 w-4" />
                    </button>
                </div>
                <p className="text-sm font-medium text-slate-500">
                    {orderIds.length} commande{orderIds.length > 1 ? 's' : ''} sélectionnée
                    {orderIds.length > 1 ? 's' : ''}.
                </p>
                {error ? (
                    <div className="mt-3 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-medium text-rose-700">
                        {error}
                    </div>
                ) : null}
                <label className="mt-4 block">
                    <span className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Livreur actif
                    </span>
                    <select
                        value={driverId}
                        onChange={(e) => setDriverId(e.target.value)}
                        className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm outline-none focus:border-blue-300 focus:bg-white focus:ring-4 focus:ring-blue-500/10"
                    >
                        {drivers.length === 0 ? <option value="">Aucun livreur actif</option> : null}
                        {drivers.map((d) => (
                            <option key={d.id} value={d.id}>
                                {d.name}
                                {d.phone ? ` · ${d.phone}` : ''}
                            </option>
                        ))}
                    </select>
                </label>
                <div className="mt-5 flex gap-2">
                    <button
                        type="button"
                        onClick={onClose}
                        className="h-10 flex-1 rounded-xl border border-slate-200 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                    >
                        Annuler
                    </button>
                    <button
                        type="button"
                        disabled={busy || !driverId}
                        onClick={() => onSubmit(Number(driverId))}
                        className="h-10 flex-1 rounded-xl bg-blue-600 text-sm font-bold text-white shadow-sm shadow-blue-500/20 hover:bg-blue-700 disabled:opacity-60"
                    >
                        {busy ? 'Affectation…' : 'Confirmer'}
                    </button>
                </div>
            </div>
        </div>
    );
}

function DetailDrawer({ order, loading, onClose }) {
    if (!order && !loading) return null;

    return (
        <div className="fixed inset-0 z-[60] flex justify-end bg-slate-900/40">
            <button type="button" className="absolute inset-0 cursor-default" aria-label="Fermer" onClick={onClose} />
            <aside className="relative z-10 flex h-full w-full max-w-lg flex-col bg-white shadow-2xl">
                <div className="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                    <div>
                        <p className="text-sm font-bold text-slate-900">{orderDisplayName(order)}</p>
                        <p className="text-xs font-medium text-slate-500">{order?.customer_name || '—'}</p>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100"
                    >
                        <X className="h-4 w-4" />
                    </button>
                </div>
                <div className="flex-1 overflow-y-auto px-4 py-4">
                    {loading && !order ? (
                        <p className="text-sm text-slate-500">Chargement…</p>
                    ) : (
                        <div className="space-y-4 text-sm">
                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <p className="text-[11px] font-semibold uppercase text-slate-400">Téléphone</p>
                                    <p className="mt-0.5 font-semibold text-slate-800">{order.phone || '—'}</p>
                                </div>
                                <div>
                                    <p className="text-[11px] font-semibold uppercase text-slate-400">Ville</p>
                                    <p className="mt-0.5 font-semibold text-slate-800">{order.city || '—'}</p>
                                </div>
                                <div className="col-span-2">
                                    <p className="text-[11px] font-semibold uppercase text-slate-400">Adresse</p>
                                    <p className="mt-0.5 font-semibold text-slate-800">{order.address || '—'}</p>
                                </div>
                                <div>
                                    <p className="text-[11px] font-semibold uppercase text-slate-400">Montant</p>
                                    <p className="mt-0.5 font-bold text-slate-900">
                                        {formatMoney(order.total_price, order.currency)}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-[11px] font-semibold uppercase text-slate-400">Statut</p>
                                    <span
                                        className="mt-0.5 inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                        style={statusBadgeStyle(order.delivery_status_color)}
                                    >
                                        {order.delivery_status_label}
                                    </span>
                                </div>
                            </div>
                            <div>
                                <p className="text-[11px] font-semibold uppercase text-slate-400">Produits</p>
                                <p className="mt-1 font-medium text-slate-700">{productsLabel(order.line_items)}</p>
                            </div>
                            {order.note ? (
                                <div>
                                    <p className="text-[11px] font-semibold uppercase text-slate-400">Note</p>
                                    <p className="mt-1 font-medium text-slate-700">{order.note}</p>
                                </div>
                            ) : null}
                            <div>
                                <p className="mb-2 text-[11px] font-semibold uppercase text-slate-400">Historique</p>
                                <ul className="space-y-2">
                                    {[...(order.confirmation_history || [])].reverse().map((entry, idx) => (
                                        <li
                                            key={`${entry.at}-${idx}`}
                                            className="rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 text-xs font-medium text-slate-700"
                                        >
                                            {formatHistoryLine(entry)}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        </div>
                    )}
                </div>
            </aside>
        </div>
    );
}

export default function Assignment() {
    const [orders, setOrders] = useState([]);
    const [counts, setCounts] = useState({});
    const [meta, setMeta] = useState({ current_page: 1, last_page: 1, per_page: 25, total: 0 });
    const [search, setSearch] = useState('');
    const [debouncedSearch, setDebouncedSearch] = useState('');
    const [city, setCity] = useState('');
    const [debouncedCity, setDebouncedCity] = useState('');
    const [filter, setFilter] = useState('to_assign');
    const [page, setPage] = useState(1);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [selected, setSelected] = useState(() => new Set());
    const [drivers, setDrivers] = useState([]);
    const [assignOpen, setAssignOpen] = useState(false);
    const [assignIds, setAssignIds] = useState([]);
    const [busy, setBusy] = useState(false);
    const [assignError, setAssignError] = useState('');
    const [toast, setToast] = useState('');
    const [detail, setDetail] = useState(null);
    const [detailLoading, setDetailLoading] = useState(false);

    useEffect(() => {
        const t = setTimeout(() => setDebouncedSearch(search.trim()), 300);
        return () => clearTimeout(t);
    }, [search]);

    useEffect(() => {
        const t = setTimeout(() => setDebouncedCity(city.trim()), 300);
        return () => clearTimeout(t);
    }, [city]);

    useEffect(() => {
        setPage(1);
        setSelected(new Set());
    }, [debouncedSearch, debouncedCity, filter]);

    const loadDrivers = useCallback(async () => {
        try {
            const { data } = await window.axios.get('/api/drivers/active');
            setDrivers(data.drivers || []);
        } catch {
            setDrivers([]);
        }
    }, []);

    const load = useCallback(async () => {
        setLoading(true);
        setError('');
        try {
            const { data } = await window.axios.get('/api/assignment/orders', {
                params: {
                    page,
                    per_page: 25,
                    search: debouncedSearch || undefined,
                    city: debouncedCity || undefined,
                    filter,
                },
            });
            setOrders(data.orders || []);
            setCounts(data.counts || {});
            setMeta(data.meta || { current_page: 1, last_page: 1, per_page: 25, total: 0 });
        } catch (err) {
            setError(err.response?.data?.message || 'Impossible de charger les commandes.');
            setOrders([]);
        } finally {
            setLoading(false);
        }
    }, [page, debouncedSearch, debouncedCity, filter]);

    useEffect(() => {
        load();
    }, [load]);

    useEffect(() => {
        loadDrivers();
    }, [loadDrivers]);

    const toggleOne = (id) => {
        setSelected((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    };

    const assignableOnPage = useMemo(
        () => orders.filter((o) => o.can_assign).map((o) => o.id),
        [orders],
    );

    const allPageSelected =
        assignableOnPage.length > 0 && assignableOnPage.every((id) => selected.has(id));

    const toggleAllPage = () => {
        setSelected((prev) => {
            const next = new Set(prev);
            if (allPageSelected) {
                assignableOnPage.forEach((id) => next.delete(id));
            } else {
                assignableOnPage.forEach((id) => next.add(id));
            }
            return next;
        });
    };

    const openAssign = (ids) => {
        setAssignIds(ids);
        setAssignError('');
        setAssignOpen(true);
        loadDrivers();
    };

    const submitAssign = async (driverId) => {
        setBusy(true);
        setAssignError('');
        try {
            const { data } = await window.axios.post('/api/assignment/assign', {
                order_ids: assignIds,
                driver_id: driverId,
            });
            setAssignOpen(false);
            setSelected(new Set());
            setToast(data.message || 'Affectation réussie.');
            window.setTimeout(() => setToast(''), 2500);
            await load();
        } catch (err) {
            setAssignError(
                err.response?.data?.message ||
                    Object.values(err.response?.data?.errors || {})?.[0]?.[0] ||
                    'Affectation impossible.',
            );
        } finally {
            setBusy(false);
        }
    };

    const openDetail = async (order) => {
        setDetail(order);
        setDetailLoading(true);
        try {
            const { data } = await window.axios.get(`/api/assignment/orders/${order.id}`);
            setDetail(data.order);
        } catch {
            /* keep list payload */
        } finally {
            setDetailLoading(false);
        }
    };

    return (
        <div className="space-y-4 sm:space-y-6">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">À attribuer</h1>
                    <p className="mt-1 text-sm font-medium text-slate-500">
                        Commandes confirmées à affecter aux livreurs Lavfast.
                    </p>
                </div>
                <button
                    type="button"
                    onClick={load}
                    disabled={loading}
                    className="inline-flex h-10 items-center gap-2 self-start rounded-xl border border-slate-200 bg-white px-4 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50 disabled:opacity-60"
                >
                    <RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />
                    Actualiser
                </button>
            </div>

            {toast ? (
                <div className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">
                    {toast}
                </div>
            ) : null}

            <div className="rounded-2xl border border-slate-200/80 bg-white p-3 shadow-sm sm:p-4">
                <div className="flex flex-col gap-3 sm:flex-row">
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
                    <input
                        type="search"
                        value={city}
                        onChange={(e) => setCity(e.target.value)}
                        placeholder="Filtrer par ville…"
                        className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm outline-none transition focus:border-blue-300 focus:bg-white focus:ring-4 focus:ring-blue-500/10 sm:w-48"
                    />
                </div>
                <div className="mt-3 flex gap-1.5 overflow-x-auto pb-1">
                    {FILTERS.map((item) => {
                        const active = filter === item.value;
                        const count = counts[item.value] ?? 0;
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
                                    {count}
                                </span>
                            </button>
                        );
                    })}
                </div>
            </div>

            {selected.size > 0 ? (
                <div className="flex flex-col gap-2 rounded-2xl border border-blue-200 bg-blue-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <p className="text-sm font-semibold text-blue-800">
                        {selected.size} sélectionnée{selected.size > 1 ? 's' : ''}
                    </p>
                    <button
                        type="button"
                        onClick={() => openAssign([...selected])}
                        className="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-bold text-white shadow-sm hover:bg-blue-700"
                    >
                        <Bike className="h-4 w-4" />
                        Affecter à un livreur
                    </button>
                </div>
            ) : null}

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
                    <p className="mt-4 text-sm font-semibold text-slate-800">Aucune commande</p>
                    <p className="mt-1 max-w-sm text-sm font-medium text-slate-500">
                        Les commandes confirmées sans livreur apparaissent ici.
                    </p>
                </div>
            ) : (
                <div className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-left text-sm">
                            <thead className="border-b border-slate-100 bg-slate-50/80 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th className="px-3 py-3">
                                        {filter === 'to_assign' || filter === 'failed' ? (
                                            <input
                                                type="checkbox"
                                                checked={allPageSelected}
                                                onChange={toggleAllPage}
                                                className="h-4 w-4 rounded border-slate-300 text-blue-600"
                                            />
                                        ) : null}
                                    </th>
                                    <th className="px-3 py-3">Commande</th>
                                    <th className="px-3 py-3">Client</th>
                                    <th className="px-3 py-3">Ville / Adresse</th>
                                    <th className="px-3 py-3">Produits</th>
                                    <th className="px-3 py-3">Montant</th>
                                    <th className="px-3 py-3">Date</th>
                                    <th className="px-3 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {orders.map((order) => (
                                    <tr key={order.id} className="hover:bg-slate-50/60">
                                        <td className="px-3 py-3 align-top">
                                            {order.can_assign ? (
                                                <input
                                                    type="checkbox"
                                                    checked={selected.has(order.id)}
                                                    onChange={() => toggleOne(order.id)}
                                                    className="mt-1 h-4 w-4 rounded border-slate-300 text-blue-600"
                                                />
                                            ) : null}
                                        </td>
                                        <td className="px-3 py-3 align-top">
                                            <button
                                                type="button"
                                                onClick={() => openDetail(order)}
                                                className="text-left font-bold text-slate-900 hover:text-blue-700"
                                            >
                                                {orderDisplayName(order)}
                                            </button>
                                            <div className="mt-1">
                                                <span
                                                    className="inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                                    style={statusBadgeStyle(order.delivery_status_color)}
                                                >
                                                    {order.delivery_status_label}
                                                </span>
                                            </div>
                                            {order.driver_name ? (
                                                <p className="mt-1 text-[11px] font-medium text-slate-500">
                                                    {order.driver_name}
                                                </p>
                                            ) : null}
                                        </td>
                                        <td className="px-3 py-3 align-top">
                                            <p className="font-semibold text-slate-800">
                                                {order.customer_name || '—'}
                                            </p>
                                            <p className="mt-0.5 text-xs font-medium text-slate-500">
                                                {order.phone || '—'}
                                            </p>
                                        </td>
                                        <td className="px-3 py-3 align-top">
                                            <p className="font-semibold text-slate-800">{order.city || '—'}</p>
                                            <p className="mt-0.5 max-w-[220px] truncate text-xs font-medium text-slate-500">
                                                {order.address || '—'}
                                            </p>
                                        </td>
                                        <td className="max-w-[200px] px-3 py-3 align-top text-xs font-medium text-slate-600">
                                            <span className="line-clamp-2">{productsLabel(order.line_items)}</span>
                                        </td>
                                        <td className="px-3 py-3 align-top font-bold text-slate-900">
                                            {formatMoney(order.total_price, order.currency)}
                                        </td>
                                        <td className="px-3 py-3 align-top text-xs font-medium text-slate-500">
                                            {formatDate(order.confirmed_at || order.shopify_created_at)}
                                        </td>
                                        <td className="px-3 py-3 align-top">
                                            {order.can_assign ? (
                                                <button
                                                    type="button"
                                                    onClick={() => openAssign([order.id])}
                                                    className="rounded-lg bg-blue-600 px-2.5 py-1.5 text-xs font-bold text-white hover:bg-blue-700"
                                                >
                                                    Affecter
                                                </button>
                                            ) : null}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
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

            <AssignModal
                open={assignOpen}
                orderIds={assignIds}
                drivers={drivers}
                busy={busy}
                error={assignError}
                onClose={() => setAssignOpen(false)}
                onSubmit={submitAssign}
            />

            {detail ? (
                <DetailDrawer order={detail} loading={detailLoading} onClose={() => setDetail(null)} />
            ) : null}
        </div>
    );
}
