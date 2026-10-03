import { useCallback, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowRightLeft, History, MapPin, MessageCircle, Phone } from 'lucide-react';
import api, { errorMessage } from '../../lib/api';
import { formatDH, formatDateTime } from '../../lib/format';
import { Alert, Checkbox, Spinner } from '../ui';
import { ProductPhoto } from './orderColumns';
import QuickShip from './QuickShip';
import StatusMoveDialog from './StatusMoveDialog';
import { telUrl, whatsappUrl } from '../../pages/confirmationHelpers';

function needsInput(status) {
    const req = status.required_fields || [];
    return req.length > 0 || status.category === 'succes';
}

function KanbanCard({ order, selected, onToggle, onDragStart, onMove, onChanged }) {
    const maps =
        order.address || order.city ? `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent([order.address, order.city].filter(Boolean).join(', '))}` : null;
    const icon = 'inline-flex h-7 w-7 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-800';
    return (
        <article
            draggable
            onDragStart={(e) => onDragStart(e, order)}
            className={`group rounded-xl border bg-white p-2.5 text-xs shadow-sm transition ${selected ? 'border-blue-300 ring-2 ring-blue-100' : 'border-slate-200 hover:border-slate-300'} cursor-grab active:cursor-grabbing`}
        >
            <div className="flex items-start gap-2">
                <Checkbox checked={selected} onChange={() => onToggle(order.id)} aria-label={`Sélectionner ${order.reference}`} className="mt-0.5" />
                <ProductPhoto order={order} size="h-10 w-10" />
                <div className="min-w-0 flex-1">
                    <div className="flex items-center justify-between gap-1">
                        <Link to={`/commandes/${order.id}`} className="font-bold text-slate-900 hover:text-blue-700">
                            {order.reference}
                        </Link>
                        <span className="font-bold text-slate-900">{formatDH(order.amount)}</span>
                    </div>
                    <div className="truncate font-medium text-slate-700" title={order.product_name || ''}>
                        {order.quantity > 1 ? `${order.quantity}× ` : ''}
                        {order.product_name || '—'}
                    </div>
                    <div className="truncate text-slate-500">
                        {order.client_blocked ? <span className="mr-1 font-bold text-rose-600">⛔</span> : null}
                        {order.customer_name} · {order.city || '—'}
                    </div>
                </div>
            </div>
            <div className="mt-1.5 flex flex-wrap items-center gap-1 text-[10px]">
                <span className={`rounded px-1.5 py-0.5 font-semibold ${order.payment_method === 'paye' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'}`}>
                    {order.payment_method === 'paye' ? 'Payé' : 'COD'}
                </span>
                {order.shipment ? (
                    <span className="rounded bg-slate-100 px-1.5 py-0.5 font-semibold text-slate-600">{order.shipment.carrier_label}</span>
                ) : order.driver ? (
                    <span className="rounded bg-indigo-50 px-1.5 py-0.5 font-semibold text-indigo-700">{order.driver.name}</span>
                ) : null}
                <span className="ml-auto text-slate-400">{formatDateTime(order.created_at).slice(0, 10)}</span>
            </div>
            <div className="mt-1 flex items-center gap-0.5 border-t border-slate-100 pt-1">
                {telUrl(order.customer_phone) ? (
                    <a href={telUrl(order.customer_phone)} className={icon} title="Appeler" aria-label="Appeler">
                        <Phone className="h-3.5 w-3.5" />
                    </a>
                ) : null}
                {whatsappUrl(order.customer_phone) ? (
                    <a href={whatsappUrl(order.customer_phone)} target="_blank" rel="noreferrer" className={icon} title="WhatsApp" aria-label="WhatsApp">
                        <MessageCircle className="h-3.5 w-3.5" />
                    </a>
                ) : null}
                {maps ? (
                    <a href={maps} target="_blank" rel="noreferrer" className={icon} title="Localisation" aria-label="Localisation">
                        <MapPin className="h-3.5 w-3.5" />
                    </a>
                ) : null}
                <Link to={`/commandes/${order.id}`} className={icon} title="Historique" aria-label="Historique">
                    <History className="h-3.5 w-3.5" />
                </Link>
                <div className="ml-auto flex items-center gap-0.5">
                    <QuickShip order={order} compact onChanged={onChanged} />
                    <button type="button" className={icon} title="Changer de statut" aria-label="Changer de statut" onClick={() => onMove(order)}>
                        <ArrowRightLeft className="h-3.5 w-3.5" />
                    </button>
                </div>
            </div>
        </article>
    );
}

/**
 * T12 — Kanban des commandes. Columns = active statuses (Paramètres → Statuts). Drag & drop
 * (mouse) or "Changer de statut" (touch / keyboard) → same API + validations as a manual change.
 */
export default function KanbanBoard({ filters, selection, reloadKey, onChanged }) {
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [toast, setToast] = useState(null);
    const [over, setOver] = useState(null);
    const [move, setMove] = useState(null);
    const [picker, setPicker] = useState(null); // order whose status is being changed via button
    const drag = useRef(null);

    const load = useCallback(async () => {
        try {
            const { data: d } = await api.get('/orders/kanban', {
                params: filters,
            });
            setData(d);
            setError(null);
        } catch (e) {
            setError(errorMessage(e));
        }
    }, [filters]);
    useEffect(() => {
        load();
    }, [load, reloadKey]);

    const colOf = (order) => data.columns.find((c) => c.orders.some((o) => o.id === order.id));

    function allowed(fromCol, target) {
        if (!data.transitions_enforced) return true;
        const ids = fromCol?.status?.transition_to_ids;
        return !ids || !ids.length || ids.includes(target.id);
    }

    function applyLocal(order, target, updated) {
        setData((d) => ({
            ...d,
            columns: d.columns.map((c) => {
                const had = c.orders.some((o) => o.id === order.id);
                if (c.status.id === target.id)
                    return {
                        ...c,
                        count: c.count + (had ? 0 : 1),
                        orders: [updated || order, ...c.orders.filter((o) => o.id !== order.id)],
                    };
                if (had)
                    return {
                        ...c,
                        count: c.count - 1,
                        orders: c.orders.filter((o) => o.id !== order.id),
                    };
                return c;
            }),
        }));
    }

    async function requestMove(order, target) {
        const from = colOf(order);
        if (!from || from.status.id === target.id) return;
        if (!allowed(from, target)) {
            setToast({
                type: 'error',
                text: `Transition non autorisée : « ${from.status.name} » → « ${target.name} » (Paramètres → Statuts).`,
            });
            return;
        }
        if (needsInput(target)) {
            setMove({
                orderIds: [order.id],
                status: target,
                amount: order.amount,
                order,
                label: `${order.reference} — ${order.customer_name}`,
            });
            return;
        }
        const snapshot = data;
        applyLocal(order, target);
        try {
            const { data: res } = await api.post(`/orders/${order.id}/status`, {
                delivery_status_id: target.id,
            });
            applyLocal(order, target, res.data);
            setToast({
                type: 'success',
                text: `${order.reference} → ${target.name}`,
            });
            onChanged?.();
        } catch (e) {
            setData(snapshot);
            setToast({ type: 'error', text: errorMessage(e) });
        }
    }

    if (error) return <Alert>{error}</Alert>;
    if (!data) return <Spinner />;

    return (
        <div className="space-y-2">
            {toast ? <Alert type={toast.type === 'error' ? 'error' : 'success'}>{toast.text}</Alert> : null}
            <div className="-mx-4 flex snap-x snap-mandatory gap-3 overflow-x-auto px-4 pb-3 sm:mx-0 sm:px-0" role="list" aria-label="Kanban des commandes">
                {data.columns.map((col) => (
                    <section
                        key={col.status.id}
                        role="listitem"
                        aria-label={col.status.name}
                        onDragOver={(e) => {
                            e.preventDefault();
                            setOver(col.status.id);
                        }}
                        onDragLeave={() => setOver((o) => (o === col.status.id ? null : o))}
                        onDrop={(e) => {
                            e.preventDefault();
                            setOver(null);
                            if (drag.current) requestMove(drag.current, col.status);
                            drag.current = null;
                        }}
                        className={`flex w-[82vw] shrink-0 snap-start flex-col rounded-2xl border bg-slate-50/80 sm:w-72 ${over === col.status.id ? 'border-blue-400 bg-blue-50/60' : 'border-slate-200'}`}
                    >
                        <header className="flex items-center justify-between gap-2 rounded-t-2xl border-b border-slate-200 bg-white px-3 py-2">
                            <span className="flex min-w-0 items-center gap-2">
                                <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: col.status.color }} />
                                <span className="truncate text-sm font-bold text-slate-800">{col.status.name}</span>
                            </span>
                            <span className="rounded-md bg-slate-100 px-1.5 py-0.5 text-xs font-bold text-slate-600" aria-label={`${col.count} commandes`}>
                                {col.count}
                            </span>
                        </header>
                        <div className="max-h-[calc(100vh-300px)] min-h-24 flex-1 space-y-2 overflow-y-auto p-2">
                            {col.orders.map((o) => (
                                <KanbanCard
                                    key={o.id}
                                    order={o}
                                    selected={selection.isSelected(o.id)}
                                    onToggle={selection.toggle}
                                    onDragStart={(e, order) => {
                                        drag.current = order;
                                        e.dataTransfer.effectAllowed = 'move';
                                        e.dataTransfer.setData('text/plain', String(order.id));
                                    }}
                                    onMove={(order) => setPicker(order)}
                                    onChanged={load}
                                />
                            ))}
                            {!col.orders.length ? <p className="py-6 text-center text-xs text-slate-400">Aucune commande</p> : null}
                            {col.has_more ? (
                                <button
                                    type="button"
                                    className="w-full rounded-lg py-1.5 text-xs font-semibold text-blue-700 hover:bg-blue-50"
                                    onClick={async () => {
                                        const { data: more } = await api.get('/orders/kanban', {
                                            params: {
                                                ...filters,
                                                column: col.status.code,
                                                offset: col.orders.length,
                                            },
                                        });
                                        const m = more.columns[0];
                                        setData((d) => ({
                                            ...d,
                                            columns: d.columns.map((c) =>
                                                c.status.id === col.status.id
                                                    ? {
                                                          ...c,
                                                          orders: [...c.orders, ...m.orders],
                                                          has_more: m.has_more,
                                                      }
                                                    : c,
                                            ),
                                        }));
                                    }}
                                >
                                    Voir plus ({col.count - col.orders.length})
                                </button>
                            ) : null}
                        </div>
                    </section>
                ))}
            </div>

            {picker ? (
                <div className="fixed inset-0 z-[60] flex items-end justify-center bg-slate-900/40 sm:items-center" onClick={() => setPicker(null)}>
                    <div
                        role="dialog"
                        aria-label="Changer de statut"
                        className="w-full max-w-sm rounded-t-2xl bg-white p-3 shadow-xl sm:rounded-2xl"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <div className="mb-2 px-1 text-sm font-bold text-slate-900">Changer le statut de {picker.reference}</div>
                        <div className="grid grid-cols-2 gap-1.5">
                            {data.columns.map((c) => {
                                const from = colOf(picker);
                                const current = from?.status.id === c.status.id;
                                const ok = allowed(from, c.status);
                                return (
                                    <button
                                        key={c.status.id}
                                        type="button"
                                        disabled={current || !ok}
                                        title={!ok ? 'Transition non autorisée' : ''}
                                        onClick={() => {
                                            setPicker(null);
                                            requestMove(picker, c.status);
                                        }}
                                        className="flex items-center gap-2 rounded-xl border border-slate-200 px-2.5 py-2 text-left text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-40"
                                    >
                                        <span
                                            className="h-2 w-2 rounded-full"
                                            style={{
                                                background: c.status.color,
                                            }}
                                        />{' '}
                                        {c.status.name}
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                </div>
            ) : null}

            <StatusMoveDialog
                move={move}
                onClose={() => setMove(null)}
                onDone={(res) => {
                    if (move.order && res?.data) applyLocal(move.order, move.status, res.data);
                    setToast({
                        type: 'success',
                        text: res?.message || `${move.order?.reference} → ${move.status.name}`,
                    });
                    setMove(null);
                    load();
                    onChanged?.();
                }}
            />
        </div>
    );
}
