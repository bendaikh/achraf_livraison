import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { PackageSearch, RefreshCw, Search } from 'lucide-react';
import { statusBadgeStyle } from './confirmationHelpers';

const STATUS_LABELS = {
    pending: { label: 'En attente', className: 'bg-amber-50 text-amber-700 ring-amber-600/15' },
    processing: { label: 'En cours', className: 'bg-blue-50 text-blue-700 ring-blue-600/15' },
    fulfilled: { label: 'Livrée', className: 'bg-emerald-50 text-emerald-700 ring-emerald-600/15' },
    cancelled: { label: 'Annulée', className: 'bg-rose-50 text-rose-700 ring-rose-600/15' },
};

const STATUS_FILTERS = [
    { value: 'all', label: 'Toutes' },
    { value: 'pending', label: 'En attente' },
    { value: 'processing', label: 'En cours' },
    { value: 'fulfilled', label: 'Livrées' },
    { value: 'cancelled', label: 'Annulées' },
];

function formatDate(value) {
    if (!value) return '—';
    try {
        return new Intl.DateTimeFormat('fr-FR', {
            dateStyle: 'short',
            timeStyle: 'short',
        }).format(new Date(value));
    } catch {
        return value;
    }
}

function formatMoney(amount, currency = 'MAD') {
    if (amount == null || amount === '') return '—';
    const num = Number(amount);
    if (Number.isNaN(num)) return `${amount} ${currency}`;
    return `${num.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency}`;
}

export default function Commandes() {
    const [orders, setOrders] = useState([]);
    const [meta, setMeta] = useState({ current_page: 1, last_page: 1, per_page: 25, total: 0 });
    const [search, setSearch] = useState('');
    const [debouncedSearch, setDebouncedSearch] = useState('');
    const [status, setStatus] = useState('all');
    const [page, setPage] = useState(1);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    useEffect(() => {
        const timer = setTimeout(() => setDebouncedSearch(search.trim()), 300);
        return () => clearTimeout(timer);
    }, [search]);

    useEffect(() => {
        setPage(1);
    }, [debouncedSearch, status]);

    const load = useCallback(async () => {
        setLoading(true);
        setError('');
        try {
            const { data } = await window.axios.get('/api/orders', {
                params: {
                    page,
                    per_page: 25,
                    search: debouncedSearch || undefined,
                    status: status !== 'all' ? status : undefined,
                },
            });
            setOrders(data.orders || []);
            setMeta(data.meta || { current_page: 1, last_page: 1, per_page: 25, total: 0 });
        } catch (err) {
            setError(err.response?.data?.message || 'Impossible de charger les commandes.');
            setOrders([]);
        } finally {
            setLoading(false);
        }
    }, [page, debouncedSearch, status]);

    useEffect(() => {
        load();
    }, [load]);

    return (
        <div className="space-y-4 sm:space-y-6">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">Commandes</h1>
                    <p className="mt-1 text-sm font-medium text-slate-500">
                        Liste des commandes synchronisées depuis Shopify.
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

            <section className="rounded-2xl border border-slate-200/80 bg-white shadow-sm shadow-slate-200/40">
                <div className="flex flex-col gap-3 border-b border-slate-100 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                    <div className="relative w-full max-w-md">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            type="search"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Rechercher (n°, client, téléphone…)"
                            className="h-10 w-full rounded-xl border border-slate-200 bg-slate-50 pl-9 pr-3 text-sm outline-none transition focus:border-blue-300 focus:bg-white focus:ring-4 focus:ring-blue-500/10"
                        />
                    </div>
                    <div className="flex flex-wrap gap-1.5">
                        {STATUS_FILTERS.map((filter) => {
                            const active = status === filter.value;
                            return (
                                <button
                                    key={filter.value}
                                    type="button"
                                    onClick={() => setStatus(filter.value)}
                                    className={`rounded-lg px-3 py-1.5 text-xs font-semibold transition ${
                                        active
                                            ? 'bg-blue-600 text-white shadow-sm shadow-blue-500/20'
                                            : 'bg-slate-100 text-slate-600 hover:bg-slate-200/80'
                                    }`}
                                >
                                    {filter.label}
                                </button>
                            );
                        })}
                    </div>
                </div>

                {error ? (
                    <div className="mx-4 mt-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700 sm:mx-5">
                        {error}
                    </div>
                ) : null}

                {loading && orders.length === 0 ? (
                    <div className="flex min-h-[240px] items-center justify-center text-sm font-medium text-slate-500">
                        Chargement des commandes…
                    </div>
                ) : orders.length === 0 ? (
                    <div className="flex min-h-[280px] flex-col items-center justify-center px-6 py-12 text-center">
                        <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-500">
                            <PackageSearch className="h-6 w-6" />
                        </div>
                        <p className="mt-4 text-sm font-semibold text-slate-800">Aucune commande</p>
                        <p className="mt-1 max-w-sm text-sm font-medium text-slate-500">
                            Les commandes apparaîtront ici après synchronisation Shopify.
                        </p>
                        <Link
                            to="/integrations/shopify"
                            className="mt-4 text-sm font-bold text-blue-600 hover:text-blue-700"
                        >
                            Ouvrir l’intégration Shopify
                        </Link>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-left text-sm">
                            <thead>
                                <tr className="border-b border-slate-100 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                                    <th className="px-5 py-3">Commande</th>
                                    <th className="px-3 py-3">Client</th>
                                    <th className="px-3 py-3">Téléphone</th>
                                    <th className="px-3 py-3">Ville</th>
                                    <th className="px-3 py-3">Montant</th>
                                    <th className="px-3 py-3">Statut</th>
                                    <th className="px-5 py-3">Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                {orders.map((order) => {
                                    const hasConfirmation = Boolean(order.confirmation_status);
                                    const statusMeta = hasConfirmation
                                        ? {
                                              label:
                                                  order.confirmation_status_label ||
                                                  order.confirmation_status ||
                                                  '—',
                                              style: statusBadgeStyle(order.confirmation_status_color),
                                          }
                                        : {
                                              label:
                                                  STATUS_LABELS[order.status]?.label ||
                                                  order.status ||
                                                  '—',
                                              className:
                                                  STATUS_LABELS[order.status]?.className ||
                                                  'bg-slate-100 text-slate-700 ring-slate-600/10',
                                          };

                                    return (
                                        <tr
                                            key={order.id}
                                            className="border-b border-slate-50 last:border-0 hover:bg-slate-50/70"
                                        >
                                            <td className="whitespace-nowrap px-5 py-3.5 font-semibold text-slate-800">
                                                {order.name || `#${order.order_number}`}
                                            </td>
                                            <td className="whitespace-nowrap px-3 py-3.5 font-medium text-slate-700">
                                                {order.customer_name || order.email || '—'}
                                            </td>
                                            <td className="whitespace-nowrap px-3 py-3.5 text-slate-500">
                                                {order.phone || '—'}
                                            </td>
                                            <td className="whitespace-nowrap px-3 py-3.5 text-slate-500">
                                                {order.city || '—'}
                                            </td>
                                            <td className="whitespace-nowrap px-3 py-3.5 font-semibold text-slate-800">
                                                {formatMoney(order.total_price, order.currency)}
                                            </td>
                                            <td className="whitespace-nowrap px-3 py-3.5">
                                                {hasConfirmation ? (
                                                    <span
                                                        className="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                                        style={statusMeta.style}
                                                    >
                                                        {statusMeta.label}
                                                    </span>
                                                ) : (
                                                    <span
                                                        className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset ${statusMeta.className}`}
                                                    >
                                                        {statusMeta.label}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="whitespace-nowrap px-5 py-3.5 text-slate-500">
                                                {formatDate(order.shopify_created_at)}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}

                {meta.total > 0 ? (
                    <div className="flex flex-col gap-3 border-t border-slate-100 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5">
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
