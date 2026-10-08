import { useCallback, useEffect, useState } from 'react';
import {
    CheckCircle2,
    Clock3,
    MessageCircle,
    PackageSearch,
    Phone,
    PhoneOff,
    RefreshCw,
    Search,
    X,
    XCircle,
} from 'lucide-react';
import {
    formatDate,
    formatHistoryLine,
    formatMoney,
    orderDisplayName,
    statusBadgeStyle,
    telUrl,
    whatsappUrl,
} from './confirmationHelpers';

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

function ModalShell({ title, children, onClose }) {
    return (
        <div className="fixed inset-0 z-[70] flex items-end justify-center bg-slate-900/50 p-0 sm:items-center sm:p-4">
            <button type="button" className="absolute inset-0 cursor-default" aria-label="Fermer" onClick={onClose} />
            <div className="relative z-10 w-full max-w-md rounded-t-2xl bg-white p-5 shadow-xl sm:rounded-2xl">
                <div className="mb-4 flex items-center justify-between gap-3">
                    <h3 className="text-base font-bold text-slate-900">{title}</h3>
                    <button
                        type="button"
                        onClick={onClose}
                        className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100"
                    >
                        <X className="h-4 w-4" />
                    </button>
                </div>
                {children}
            </div>
        </div>
    );
}

export default function DriverMissions() {
    const [orders, setOrders] = useState([]);
    const [driver, setDriver] = useState(null);
    const [counts, setCounts] = useState({});
    const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0 });
    const [search, setSearch] = useState('');
    const [debouncedSearch, setDebouncedSearch] = useState('');
    const [filter, setFilter] = useState('active');
    const [page, setPage] = useState(1);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [toast, setToast] = useState('');
    const [busyId, setBusyId] = useState(null);

    const [deliverOrder, setDeliverOrder] = useState(null);
    const [amountCollected, setAmountCollected] = useState('');
    const [deliverComment, setDeliverComment] = useState('');

    const [postponeOrder, setPostponeOrder] = useState(null);
    const [postponeDate, setPostponeDate] = useState('');
    const [postponeTime, setPostponeTime] = useState('');
    const [postponeComment, setPostponeComment] = useState('');

    const [failOrder, setFailOrder] = useState(null);
    const [failMode, setFailMode] = useState('no_answer');
    const [failReason, setFailReason] = useState('');
    const [failComment, setFailComment] = useState('');
    const [actionError, setActionError] = useState('');

    const [historyOrder, setHistoryOrder] = useState(null);

    useEffect(() => {
        const t = setTimeout(() => setDebouncedSearch(search.trim()), 300);
        return () => clearTimeout(t);
    }, [search]);

    useEffect(() => {
        setPage(1);
    }, [debouncedSearch, filter]);

    const load = useCallback(async () => {
        setLoading(true);
        setError('');
        try {
            const { data } = await window.axios.get('/api/driver/missions', {
                params: {
                    page,
                    per_page: 50,
                    search: debouncedSearch || undefined,
                    filter,
                },
            });
            setOrders(data.orders || []);
            setDriver(data.driver || null);
            setCounts(data.counts || {});
            setMeta(data.meta || { current_page: 1, last_page: 1, total: 0 });
        } catch (err) {
            setError(err.response?.data?.message || 'Impossible de charger vos missions.');
            setOrders([]);
        } finally {
            setLoading(false);
        }
    }, [page, debouncedSearch, filter]);

    useEffect(() => {
        load();
    }, [load]);

    const runAction = async (orderId, runner) => {
        setBusyId(orderId);
        setActionError('');
        try {
            await runner();
            await load();
        } catch (err) {
            const msg =
                err.response?.data?.message ||
                Object.values(err.response?.data?.errors || {})?.[0]?.[0] ||
                'Action impossible.';
            setActionError(msg);
            throw err;
        } finally {
            setBusyId(null);
        }
    };

    const openDeliver = (order) => {
        setDeliverOrder(order);
        setAmountCollected(String(order.amount_due ?? order.total_price ?? ''));
        setDeliverComment('');
        setActionError('');
    };

    const submitDeliver = async () => {
        if (!deliverOrder) return;
        const amount = Number(amountCollected);
        if (Number.isNaN(amount) || amount < 0) {
            setActionError('Montant invalide.');
            return;
        }
        try {
            await runAction(deliverOrder.id, async () => {
                const { data } = await window.axios.post(`/api/driver/missions/${deliverOrder.id}/deliver`, {
                    amount_collected: amount,
                    comment: deliverComment.trim() || undefined,
                });
                setToast(data.message || 'Livraison enregistrée.');
                window.setTimeout(() => setToast(''), 2500);
            });
            setDeliverOrder(null);
        } catch {
            /* actionError set */
        }
    };

    const openPostpone = (order) => {
        setPostponeOrder(order);
        setPostponeDate('');
        setPostponeTime('');
        setPostponeComment('');
        setActionError('');
    };

    const submitPostpone = async () => {
        if (!postponeOrder) return;
        if (!postponeDate || !postponeTime) {
            setActionError('Indiquez la date et l’heure.');
            return;
        }
        const postponeAt = new Date(`${postponeDate}T${postponeTime}`);
        if (Number.isNaN(postponeAt.getTime()) || postponeAt <= new Date()) {
            setActionError('La date de report doit être dans le futur.');
            return;
        }
        try {
            await runAction(postponeOrder.id, async () => {
                const { data } = await window.axios.post(`/api/driver/missions/${postponeOrder.id}/postpone`, {
                    postpone_at: postponeAt.toISOString(),
                    comment: postponeComment.trim() || undefined,
                });
                setToast(data.message || 'Commande reportée.');
                window.setTimeout(() => setToast(''), 2500);
            });
            setPostponeOrder(null);
        } catch {
            /* */
        }
    };

    const openFail = (order, mode) => {
        setFailOrder(order);
        setFailMode(mode);
        setFailReason(mode === 'no_answer' ? 'Client pas de réponse' : '');
        setFailComment('');
        setActionError('');
    };

    const submitFail = async () => {
        if (!failOrder) return;
        if (failReason.trim().length < 3) {
            setActionError('Indiquez un motif (3 caractères minimum).');
            return;
        }
        const endpoint = failMode === 'no_answer' ? 'no-answer' : 'fail';
        try {
            await runAction(failOrder.id, async () => {
                const { data } = await window.axios.post(`/api/driver/missions/${failOrder.id}/${endpoint}`, {
                    reason: failReason.trim(),
                    comment: failComment.trim() || undefined,
                });
                setToast(data.message || 'Enregistré.');
                window.setTimeout(() => setToast(''), 2500);
            });
            setFailOrder(null);
        } catch {
            /* */
        }
    };

    return (
        <div className="space-y-4 sm:space-y-6">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">Mes missions</h1>
                    <p className="mt-1 text-sm font-medium text-slate-500">
                        {driver?.name ? `Bonjour ${driver.name}` : 'Vos livraisons locales'}
                        {driver ? ` · COD détenu : ${formatMoney(driver.cod_held, 'MAD')}` : ''}
                    </p>
                </div>
                <button
                    type="button"
                    onClick={load}
                    disabled={loading}
                    className="inline-flex h-10 items-center gap-2 self-start rounded-xl border border-slate-200 bg-white px-4 text-sm font-bold text-slate-700 shadow-sm hover:bg-slate-50 disabled:opacity-60"
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
                <div className="relative">
                    <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <input
                        type="search"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="N° commande, client, téléphone…"
                        className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-9 pr-3 text-sm outline-none focus:border-blue-300 focus:bg-white focus:ring-4 focus:ring-blue-500/10"
                    />
                </div>
                <div className="mt-3 flex gap-1.5">
                    {[
                        { value: 'active', label: 'Actives', count: counts.active },
                        { value: 'delivered', label: 'Livrées', count: counts.delivered },
                    ].map((item) => (
                        <button
                            key={item.value}
                            type="button"
                            onClick={() => setFilter(item.value)}
                            className={`rounded-lg px-3 py-1.5 text-xs font-semibold transition ${
                                filter === item.value
                                    ? 'bg-blue-600 text-white'
                                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200/80'
                            }`}
                        >
                            {item.label}
                            <span className={`ml-1.5 ${filter === item.value ? 'text-blue-100' : 'text-slate-400'}`}>
                                {item.count ?? 0}
                            </span>
                        </button>
                    ))}
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
                    <p className="mt-4 text-sm font-semibold text-slate-800">Aucune mission</p>
                    <p className="mt-1 max-w-sm text-sm font-medium text-slate-500">
                        Les commandes qui vous sont affectées apparaîtront ici.
                    </p>
                </div>
            ) : (
                <div className="grid gap-3">
                    {orders.map((order) => {
                        const callHref = telUrl(order.phone);
                        const waHref = whatsappUrl(order.phone);
                        const busy = busyId === order.id;

                        return (
                            <article
                                key={order.id}
                                className="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm shadow-slate-200/40"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <h2 className="text-sm font-bold text-slate-900">
                                                {orderDisplayName(order)}
                                            </h2>
                                            <span
                                                className="inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                                style={statusBadgeStyle(order.delivery_status_color)}
                                            >
                                                {order.delivery_status_label}
                                            </span>
                                        </div>
                                        <p className="mt-1.5 text-sm font-semibold text-slate-800">
                                            {order.customer_name || 'Client'}
                                        </p>
                                        <p className="mt-0.5 text-xs font-medium text-slate-500">
                                            {[order.phone || '—', order.city || '—'].join(' · ')}
                                        </p>
                                        <p className="mt-1 text-xs font-medium text-slate-600">
                                            {order.address || 'Adresse non renseignée'}
                                        </p>
                                    </div>
                                    <div className="text-right">
                                        <p className="text-base font-bold text-slate-900">
                                            À encaisser : {formatMoney(order.amount_due ?? order.total_price, order.currency)}
                                        </p>
                                        <p className="mt-1 text-[11px] font-medium text-slate-400">
                                            {formatDate(order.assigned_at)}
                                        </p>
                                    </div>
                                </div>

                                <p className="mt-3 text-xs font-medium text-slate-600">
                                    <span className="font-semibold text-slate-500">Produits : </span>
                                    {productsLabel(order.line_items)}
                                </p>
                                {order.note ? (
                                    <p className="mt-1.5 rounded-lg bg-amber-50 px-2.5 py-1.5 text-xs font-medium text-amber-800">
                                        Note : {order.note}
                                    </p>
                                ) : null}
                                {order.delivery_postponed_until ? (
                                    <p className="mt-1.5 text-xs font-semibold text-amber-700">
                                        Reportée au {formatDate(order.delivery_postponed_until)}
                                    </p>
                                ) : null}

                                <div className="mt-4 flex flex-wrap gap-2">
                                    {callHref ? (
                                        <a
                                            href={callHref}
                                            className="inline-flex h-9 items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 text-xs font-bold text-slate-700 hover:bg-slate-50"
                                        >
                                            <Phone className="h-3.5 w-3.5" />
                                            Appeler
                                        </a>
                                    ) : null}
                                    {waHref ? (
                                        <a
                                            href={waHref}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="inline-flex h-9 items-center gap-1.5 rounded-xl border border-emerald-200 bg-emerald-50 px-3 text-xs font-bold text-emerald-700 hover:bg-emerald-100"
                                        >
                                            <MessageCircle className="h-3.5 w-3.5" />
                                            WhatsApp
                                        </a>
                                    ) : null}
                                    <button
                                        type="button"
                                        onClick={() => setHistoryOrder(order)}
                                        className="inline-flex h-9 items-center rounded-xl border border-slate-200 px-3 text-xs font-semibold text-slate-600 hover:bg-slate-50"
                                    >
                                        Historique
                                    </button>
                                </div>

                                {order.can_act ? (
                                    <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                                        <button
                                            type="button"
                                            disabled={busy}
                                            onClick={() => openDeliver(order)}
                                            className="inline-flex h-10 items-center justify-center gap-1.5 rounded-xl bg-emerald-600 text-xs font-bold text-white hover:bg-emerald-700 disabled:opacity-60"
                                        >
                                            <CheckCircle2 className="h-3.5 w-3.5" />
                                            Livrée
                                        </button>
                                        <button
                                            type="button"
                                            disabled={busy}
                                            onClick={() => openFail(order, 'no_answer')}
                                            className="inline-flex h-10 items-center justify-center gap-1.5 rounded-xl bg-slate-700 text-xs font-bold text-white hover:bg-slate-800 disabled:opacity-60"
                                        >
                                            <PhoneOff className="h-3.5 w-3.5" />
                                            Pas de réponse
                                        </button>
                                        <button
                                            type="button"
                                            disabled={busy}
                                            onClick={() => openPostpone(order)}
                                            className="inline-flex h-10 items-center justify-center gap-1.5 rounded-xl bg-amber-500 text-xs font-bold text-white hover:bg-amber-600 disabled:opacity-60"
                                        >
                                            <Clock3 className="h-3.5 w-3.5" />
                                            Reporter
                                        </button>
                                        <button
                                            type="button"
                                            disabled={busy}
                                            onClick={() => openFail(order, 'failed')}
                                            className="inline-flex h-10 items-center justify-center gap-1.5 rounded-xl bg-rose-600 text-xs font-bold text-white hover:bg-rose-700 disabled:opacity-60"
                                        >
                                            <XCircle className="h-3.5 w-3.5" />
                                            Échouée
                                        </button>
                                    </div>
                                ) : null}
                            </article>
                        );
                    })}
                </div>
            )}

            {meta.total > 50 ? (
                <div className="flex justify-between rounded-2xl border border-slate-200/80 bg-white px-4 py-3">
                    <p className="text-xs font-medium text-slate-500">
                        Page {meta.current_page} / {meta.last_page}
                    </p>
                    <div className="flex gap-2">
                        <button
                            type="button"
                            disabled={page <= 1}
                            onClick={() => setPage((p) => Math.max(1, p - 1))}
                            className="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold disabled:opacity-40"
                        >
                            Précédent
                        </button>
                        <button
                            type="button"
                            disabled={page >= meta.last_page}
                            onClick={() => setPage((p) => p + 1)}
                            className="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold disabled:opacity-40"
                        >
                            Suivant
                        </button>
                    </div>
                </div>
            ) : null}

            {deliverOrder ? (
                <ModalShell title="Marquer comme livrée" onClose={() => setDeliverOrder(null)}>
                    <p className="text-sm text-slate-500">
                        {orderDisplayName(deliverOrder)} · À encaisser :{' '}
                        {formatMoney(deliverOrder.amount_due ?? deliverOrder.total_price, deliverOrder.currency)}
                    </p>
                    {actionError ? (
                        <div className="mt-3 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                            {actionError}
                        </div>
                    ) : null}
                    <label className="mt-4 block">
                        <span className="mb-1 block text-xs font-semibold uppercase text-slate-500">
                            Montant réellement encaissé
                        </span>
                        <input
                            type="number"
                            min="0"
                            step="0.01"
                            value={amountCollected}
                            onChange={(e) => setAmountCollected(e.target.value)}
                            className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm outline-none focus:border-blue-300 focus:ring-4 focus:ring-blue-500/10"
                        />
                    </label>
                    <label className="mt-3 block">
                        <span className="mb-1 block text-xs font-semibold uppercase text-slate-500">
                            Commentaire (optionnel)
                        </span>
                        <textarea
                            value={deliverComment}
                            onChange={(e) => setDeliverComment(e.target.value)}
                            rows={2}
                            className="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm outline-none focus:border-blue-300 focus:ring-4 focus:ring-blue-500/10"
                        />
                    </label>
                    <p className="mt-2 text-xs font-medium text-amber-700">
                        Le montant reste chez vous jusqu’à validation de la clôture par l’admin.
                    </p>
                    <button
                        type="button"
                        disabled={busyId === deliverOrder.id}
                        onClick={submitDeliver}
                        className="mt-4 h-10 w-full rounded-xl bg-emerald-600 text-sm font-bold text-white hover:bg-emerald-700 disabled:opacity-60"
                    >
                        Confirmer la livraison
                    </button>
                </ModalShell>
            ) : null}

            {postponeOrder ? (
                <ModalShell title="Reporter la livraison" onClose={() => setPostponeOrder(null)}>
                    {actionError ? (
                        <div className="mb-3 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                            {actionError}
                        </div>
                    ) : null}
                    <div className="grid grid-cols-2 gap-3">
                        <label className="block">
                            <span className="mb-1 block text-xs font-semibold uppercase text-slate-500">Date</span>
                            <input
                                type="date"
                                value={postponeDate}
                                onChange={(e) => setPostponeDate(e.target.value)}
                                className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm outline-none focus:border-blue-300"
                            />
                        </label>
                        <label className="block">
                            <span className="mb-1 block text-xs font-semibold uppercase text-slate-500">Heure</span>
                            <input
                                type="time"
                                value={postponeTime}
                                onChange={(e) => setPostponeTime(e.target.value)}
                                className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm outline-none focus:border-blue-300"
                            />
                        </label>
                    </div>
                    <label className="mt-3 block">
                        <span className="mb-1 block text-xs font-semibold uppercase text-slate-500">
                            Commentaire (optionnel)
                        </span>
                        <textarea
                            value={postponeComment}
                            onChange={(e) => setPostponeComment(e.target.value)}
                            rows={2}
                            className="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm outline-none focus:border-blue-300"
                        />
                    </label>
                    <button
                        type="button"
                        disabled={busyId === postponeOrder.id}
                        onClick={submitPostpone}
                        className="mt-4 h-10 w-full rounded-xl bg-amber-500 text-sm font-bold text-white hover:bg-amber-600 disabled:opacity-60"
                    >
                        Confirmer le report
                    </button>
                </ModalShell>
            ) : null}

            {failOrder ? (
                <ModalShell
                    title={failMode === 'no_answer' ? 'Pas de réponse' : 'Livraison échouée'}
                    onClose={() => setFailOrder(null)}
                >
                    {actionError ? (
                        <div className="mb-3 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                            {actionError}
                        </div>
                    ) : null}
                    <label className="block">
                        <span className="mb-1 block text-xs font-semibold uppercase text-slate-500">Motif</span>
                        <input
                            value={failReason}
                            onChange={(e) => setFailReason(e.target.value)}
                            className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm outline-none focus:border-blue-300"
                        />
                    </label>
                    <label className="mt-3 block">
                        <span className="mb-1 block text-xs font-semibold uppercase text-slate-500">
                            Commentaire (optionnel)
                        </span>
                        <textarea
                            value={failComment}
                            onChange={(e) => setFailComment(e.target.value)}
                            rows={2}
                            className="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm outline-none focus:border-blue-300"
                        />
                    </label>
                    <button
                        type="button"
                        disabled={busyId === failOrder.id}
                        onClick={submitFail}
                        className={`mt-4 h-10 w-full rounded-xl text-sm font-bold text-white disabled:opacity-60 ${
                            failMode === 'no_answer' ? 'bg-slate-700 hover:bg-slate-800' : 'bg-rose-600 hover:bg-rose-700'
                        }`}
                    >
                        Enregistrer
                    </button>
                </ModalShell>
            ) : null}

            {historyOrder ? (
                <ModalShell title={`Historique · ${orderDisplayName(historyOrder)}`} onClose={() => setHistoryOrder(null)}>
                    <ul className="max-h-[50vh] space-y-2 overflow-y-auto">
                        {[...(historyOrder.confirmation_history || [])].reverse().map((entry, idx) => (
                            <li
                                key={`${entry.at}-${idx}`}
                                className="rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 text-xs font-medium text-slate-700"
                            >
                                {formatHistoryLine(entry)}
                            </li>
                        ))}
                    </ul>
                </ModalShell>
            ) : null}
        </div>
    );
}
