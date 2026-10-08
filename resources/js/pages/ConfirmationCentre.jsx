import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import {
    ArrowLeft, CheckCircle2, ChevronDown, ChevronLeft, ChevronRight, Clock3, Headphones, Inbox, MessageCircle, MoreHorizontal, Pause, Phone, PhoneCall,
    PhoneOff, Play, Plus, Tag, Trash2, X, XCircle,
} from 'lucide-react';
import api, { errorMessage } from '../lib/api';
import { useAuth } from '../contexts/AuthContext';
import { useMeta } from '../context/MetaContext';
import { formatDH } from '../lib/format';
import OrderItemsEditor from '../components/orders/OrderItemsEditor';
import { CancelOrderDialog } from '../components/orders/OrderLifecycleDialogs';
import ConfirmationStats from '../components/confirmation/ConfirmationStats';
import { BlockedClientAlert } from '../components/clients/ClientBadges';
import { Alert, Button, Card, Field, Input, Select, Spinner, Textarea } from '../components/ui';
import { formatHistoryDate, formatHistoryLine, orderDisplayName, statusBadgeStyle, telUrl } from './confirmationHelpers';

const CALL_RESULTS = [
    ['answered', 'Répondu', 'text-emerald-700 bg-emerald-50'],
    ['no_answer', 'Pas de réponse', 'text-orange-700 bg-orange-50'],
    ['callback', 'Rappel demandé', 'text-violet-700 bg-violet-50'],
    ['wrong_number', 'Numéro incorrect', 'text-rose-700 bg-rose-50'],
];
const CHANNELS = { phone: 'Téléphone', whatsapp: 'WhatsApp', voip: 'VoIP' };
const AUTO_KEY = 'lavfast:confirmation-auto-next';

/**
 * T5 — Centre de confirmation: full page for one order of the confirmation queue.
 * Main zone = order sheet; side zone = contact, call log, confirmation actions, discounts;
 * top = agent / queue stats + previous / next navigation. Same orders & statuses as the list.
 */
export default function ConfirmationCentre() {
    const { id } = useParams();
    const [params] = useSearchParams();
    const navigate = useNavigate();
    const { can } = useAuth();
    const metaCtx = useMeta();
    const queueQs = new URLSearchParams(Object.fromEntries([...params.entries()].filter(([k, v]) => ['filter', 'search', 'agent'].includes(k) && v))).toString();

    const [order, setOrder] = useState(null);
    const [sib, setSib] = useState(null);
    const [error, setError] = useState('');
    const [toast, setToast] = useState('');
    const [busy, setBusy] = useState(false);
    const [statsKey, setStatsKey] = useState(0);
    const [auto, setAuto] = useState(() => window.localStorage.getItem(AUTO_KEY) === '1');
    const [modal, setModal] = useState(null); // postpone | cancel | call | discount | status | menu
    const [statuses, setStatuses] = useState([]);
    const [centreStats, setCentreStats] = useState(null);

    const load = useCallback(async () => {
        setError('');
        try {
            const [{ data: d }, { data: s }] = await Promise.all([
                api.get(`/confirmation/orders/${id}`),
                api.get(`/confirmation/orders/${id}/siblings`, { params: Object.fromEntries(new URLSearchParams(queueQs)) }),
            ]);
            setOrder(d.order);
            setStatuses(d.statuses || []);
            setSib(s);
        } catch (e) {
            setError(errorMessage(e));
        }
    }, [id, queueQs]);

    useEffect(() => {
        setOrder(null);
        setModal(null);
        load();
    }, [load]);

    useEffect(() => {
        window.localStorage.setItem(AUTO_KEY, auto ? '1' : '0');
    }, [auto]);

    const go = (targetId) => targetId && navigate(`/confirmation/${targetId}${queueQs ? `?${queueQs}` : ''}`);

    function flash(message) {
        setToast(message);
        window.setTimeout(() => setToast(''), 2500);
    }

    /** Runs a confirmation action; with the queue running, opens the next order automatically. */
    async function act(request, { advance = true } = {}) {
        setBusy(true);
        setError('');
        const nextBefore = sib?.next_id;
        try {
            const { data } = await request();
            setStatsKey((k) => k + 1);
            if (advance && auto) {
                const { data: s } = await api.get(`/confirmation/orders/${id}/siblings`, { params: Object.fromEntries(new URLSearchParams(queueQs)) });
                const target = s.in_queue ? s.next_id : s.next_id || nextBefore;
                if (target && String(target) !== String(id)) {
                    flash(`${data.message || 'Enregistré.'} Commande suivante…`);
                    go(target);
                    return;
                }
            }
            if (data?.order) setOrder(data.order);
            else await load();
            flash(data?.message || 'Enregistré.');
            setModal(null);
        } catch (e) {
            setError(e?.response?.data?.message || Object.values(e?.response?.data?.errors || {})?.[0]?.[0] || errorMessage(e));
        } finally {
            setBusy(false);
        }
    }

    const full = order?.full;
    const canAct = Boolean(order?.can_act);

    return (
        <div className="space-y-3 pb-24 lg:pb-0">
            {/* Top: navigation + queue */}
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex min-w-0 items-center gap-2">
                    <Link to={`/confirmation${queueQs ? `?${queueQs}` : ''}`} className="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-500 hover:bg-slate-50" aria-label="Retour à la file">
                        <ArrowLeft className="h-4 w-4" />
                    </Link>
                    <div className="min-w-0">
                        <div className="flex items-center gap-2">
                            <h1 className="truncate text-lg font-bold text-slate-900">{order ? orderDisplayName(order) : 'Centre de confirmation'}</h1>
                            {order ? (
                                <span className="rounded-full px-2.5 py-0.5 text-xs font-semibold" style={statusBadgeStyle(order.confirmation_status_color)}>
                                    {order.confirmation_status_label}
                                </span>
                            ) : null}
                        </div>
                        <p className="text-xs text-slate-500">
                            {sib ? (sib.in_queue ? `Commande ${sib.position} / ${sib.total} dans la file` : `${sib.total} commande(s) restantes dans la file`) : 'Centre de confirmation'}
                            {centreStats?.recall ? ` · En retard ${centreStats.recall.overdue ?? 0} · Aujourd’hui ${centreStats.recall.today ?? 0} · À venir ${centreStats.recall.upcoming ?? 0}` : ''}
                        </p>
                    </div>
                </div>
                <div className="flex items-center gap-2">
                    <Button variant={auto ? 'primary' : 'secondary'} size="sm" onClick={() => setAuto((v) => !v)} title="Ouvrir automatiquement la commande suivante après chaque action">
                        {auto ? <Pause className="h-3.5 w-3.5" /> : <Play className="h-3.5 w-3.5" />} {auto ? 'Pause' : 'Démarrer la file'}
                    </Button>
                    <Button variant="secondary" size="sm" disabled={!sib?.prev_id} onClick={() => go(sib.prev_id)} aria-label="Commande précédente">
                        <ChevronLeft className="h-4 w-4" />
                        <span className="hidden sm:inline">Précédente</span>
                    </Button>
                    <Button variant="secondary" size="sm" disabled={!sib?.next_id || String(sib.next_id) === String(id)} onClick={() => go(sib.next_id)} aria-label="Commande suivante">
                        <span className="hidden sm:inline">Suivante</span>
                        <ChevronRight className="h-4 w-4" />
                    </Button>
                </div>
            </div>

            <ConfirmationStats refreshKey={statsKey} onLoaded={setCentreStats} />

            {toast ? <Alert type="success">{toast}</Alert> : null}
            <Alert>{error}</Alert>

            {!order ? (
                <Spinner />
            ) : (
                <>
                <BlockedClientAlert block={order.full?.client_blocked || order.client_blocked} />
                <div className="grid gap-3 lg:grid-cols-[minmax(0,1fr)_360px]">
                    {/* Main zone: order sheet */}
                    <div className="min-w-0 space-y-3">
                        {/* Mobile: contact first */}
                        <Card title="Contact" className="lg:hidden">
                            <Contact order={order} onLogCall={() => setModal('call')} />
                        </Card>
                        <Card title="Informations commande" subtitle={`Reçue le ${formatHistoryDate(order.received_at)}${order.shop_name ? ` · ${order.shop_name}` : ''}`}>
                            <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-3">
                                <Info label="N° commande" value={orderDisplayName(order)} />
                                <Info label="Source" value={order.source_kind || order.source || 'Flow'} />
                                <Info label="Date et heure" value={formatHistoryDate(order.received_at)} />
                                <Info label="Statut confirmation" value={`${order.confirmation_status_label || '—'}${order.confirmation_inactive ? ' · inactif' : ''}`} />
                                <Info label="Statut paiement" value={order.payment_label} />
                                <Info label="Moyen de paiement" value={order.payment_method === 'paye' ? 'Carte' : order.payment_method === 'partial' ? 'Partiel' : 'COD'} />
                                <Info label="Total commande" value={formatDH(order.total_price)} />
                                <Info label="Déjà payé" value={formatDH(order.amount_paid || 0)} />
                                <Info label="Reste à payer / à encaisser" value={formatDH(order.amount_due ?? 0)} />
                            </dl>
                            <p className="mt-3 rounded-xl bg-blue-50 px-3 py-2 text-sm font-extrabold text-blue-900">{order.payment_indicator}</p>
                        </Card>

                        <Card title="Produits" bodyClassName="p-3 sm:p-4">
                            {full ? <OrderItemsEditor order={full} onChanged={(o) => setOrder((prev) => ({ ...prev, full: o, total_price: o.amount }))} compact /> : null}
                        </Card>

                        <Card title="Montants">
                            <Totals order={order} full={full} />
                        </Card>

                        <Card title="Notes">
                            <Notes
                                order={order}
                                busy={busy}
                                onSave={(payload) => act(() => api.put(`/confirmation/orders/${id}/notes`, payload), { advance: false })}
                            />
                        </Card>

                        <Card title="Historique des appels / tentatives">
                            <ul className="space-y-1.5">
                                {(order.timeline || []).length === 0 && (order.history_lines || []).length === 0 ? <li className="text-sm text-slate-400">Aucun événement</li> : null}
                                {(order.timeline || []).map((entry, i) => (
                                    <li key={`t-${entry.at}-${i}`} className="rounded-xl border border-slate-100 px-3 py-1.5 text-sm text-slate-700">{entry.label}</li>
                                ))}
                                {(order.history_lines || []).map((entry) => (
                                    <li key={entry.id} className="rounded-xl border border-slate-100 px-3 py-1.5 text-sm text-slate-700">{entry.formatted || formatHistoryLine(entry)}</li>
                                ))}
                            </ul>
                        </Card>
                    </div>

                    {/* Side zone: contact + actions */}
                    <div className="space-y-3">
                        <Card title="Contact" className="hidden lg:block">
                            <Contact order={order} onLogCall={() => setModal('call')} />
                        </Card>

                        <Card title="Confirmation">
                            <div className="space-y-1.5 text-sm">
                                <Row label="Statut" value={order.confirmation_status_label} />
                                <Row label="Agent assigné" value={full?.assigned_user?.name || '—'} />
                                <Row label="Canal" value={order.confirmation_channel ? CHANNELS[order.confirmation_channel] : '—'} />
                                <Row label="Confirmée le" value={order.confirmed_at ? `${formatHistoryDate(order.confirmed_at)}${order.confirmed_by_name ? ` · ${order.confirmed_by_name}` : ''}` : '—'} />
                                <Row label="Dernière action" value={order.confirmation_acted_at ? `${formatHistoryDate(order.confirmation_acted_at)}${order.confirmation_acted_by_name ? ` · ${order.confirmation_acted_by_name}` : ''}` : '—'} />
                                {order.postponed_until ? <Row label="Rappel prévu" value={formatHistoryDate(order.postponed_until)} /> : null}
                                {order.cancellation_reason ? <Row label="Motif d’annulation" value={order.cancellation_reason} /> : null}
                            </div>
                            {can('orders.assign_agent') ? (
                                <div className="mt-3">
                                    <Field label="Réaffecter">
                                        <Select
                                            value={order.assigned_user_id || ''}
                                            onChange={(e) => {
                                                const userId = e.target.value ? Number(e.target.value) : null;
                                                act(() => api.post('/orders/assign-agent', { order_ids: [order.id], user_id: userId }), { advance: false }).then(load);
                                            }}
                                        >
                                            <option value="">Non assignée</option>
                                            {(metaCtx.users || []).map((u) => (
                                                <option key={u.id} value={u.id}>{u.name}</option>
                                            ))}
                                        </Select>
                                    </Field>
                                </div>
                            ) : null}
                            <div className="mt-3 hidden lg:block">
                                <Actions
                                    canAct={canAct}
                                    busy={busy}
                                    onConfirm={(channel) => act(() => api.post(`/confirmation/orders/${id}/confirm`, { channel }))}
                                    onNoAnswer={() => act(() => api.post(`/confirmation/orders/${id}/no-answer`))}
                                    onPostpone={() => setModal('postpone')}
                                    onCancel={() => setModal('cancel')}
                                    onChangeStatus={() => setModal('status')}
                                    onOrderCancel={can('orders.cancel') ? () => setModal('order-cancel') : null}
                                />
                            </div>
                        </Card>

                        <Card title="Historique des appels">
                            <Calls calls={order.calls} />
                        </Card>

                        <Card
                            title="Remises"
                            actions={
                                can('orders.discount') ? (
                                    <Button size="sm" variant="secondary" onClick={() => setModal('discount')}>
                                        <Plus className="h-3.5 w-3.5" /> Ajouter une remise
                                    </Button>
                                ) : null
                            }
                        >
                            <Discounts order={order} canRemove={can('orders.discount')} onRemove={(d) => window.confirm('Retirer cette remise ?') && act(() => api.delete(`/confirmation/orders/${id}/discounts/${d.id}`), { advance: false }).then(load)} />
                        </Card>
                    </div>
                </div>
                </>
            )}

            {/* Mobile: actions always reachable at the bottom */}
            {order ? (
                <div className="fixed inset-x-0 bottom-0 z-30 border-t border-slate-200 bg-white/95 px-3 py-2 backdrop-blur lg:hidden">
                    <Actions
                        canAct={canAct}
                        busy={busy}
                        mobile
                        onConfirm={(channel) => act(() => api.post(`/confirmation/orders/${id}/confirm`, { channel }))}
                        onNoAnswer={() => act(() => api.post(`/confirmation/orders/${id}/no-answer`))}
                        onPostpone={() => setModal('postpone')}
                        onCancel={() => setModal('cancel')}
                        onChangeStatus={() => setModal('status')}
                    />
                </div>
            ) : null}

            {modal === 'postpone' ? <PostponeModal busy={busy} onClose={() => setModal(null)} onSubmit={(p) => act(() => api.post(`/confirmation/orders/${id}/postpone`, p))} /> : null}
            {modal === 'cancel' ? <CancelModal busy={busy} onClose={() => setModal(null)} onSubmit={(p) => act(() => api.post(`/confirmation/orders/${id}/cancel`, p))} /> : null}
            {modal === 'status' ? (
                <StatusModal
                    statuses={statuses}
                    products={order?.products || []}
                    busy={busy}
                    onClose={() => setModal(null)}
                    onSubmit={(p) => act(() => api.post(`/confirmation/orders/${id}/status`, p))}
                />
            ) : null}
            {modal === 'order-cancel' && order ? (
                <CancelOrderDialog
                    orders={[order]}
                    onClose={() => setModal(null)}
                    onDone={() => {
                        setModal(null);
                        load();
                    }}
                />
            ) : null}
            {modal === 'call' ? <CallModal busy={busy} onClose={() => setModal(null)} onSubmit={(p) => act(() => api.post(`/confirmation/orders/${id}/calls`, p), { advance: false }).then(load)} /> : null}
            {modal === 'discount' ? <DiscountModal busy={busy} total={order?.total_price} onClose={() => setModal(null)} onSubmit={(p) => act(() => api.post(`/confirmation/orders/${id}/discounts`, p), { advance: false }).then(load)} /> : null}
        </div>
    );
}

function Info({ label, value, strong, className = '' }) {
    return (
        <div className={`min-w-0 ${className}`}>
            <dt className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{label}</dt>
            <dd className={`truncate ${strong ? 'font-bold text-slate-900' : 'font-medium text-slate-700'}`}>{value || '—'}</dd>
        </div>
    );
}

function Row({ label, value }) {
    return (
        <div className="flex items-start justify-between gap-3">
            <span className="text-slate-500">{label}</span>
            <span className="text-right font-medium text-slate-800">{value || '—'}</span>
        </div>
    );
}

function Totals({ order, full }) {
    const subtotal = full?.items_subtotal ?? null;
    const shipping = Number(order.shipping_price || 0);
    const total = Number(order.total_price || 0);
    const paidAmount = Number(order.amount_paid ?? (full?.amount_paid || 0));
    const due = Number(order.amount_due ?? full?.amount_due ?? (full?.payment_method === 'paye' ? 0 : total));
    return (
        <div className="space-y-1.5 text-sm">
            <Row label="Sous-total produits" value={subtotal !== null ? formatDH(subtotal) : '—'} />
            <Row label="Frais de livraison" value={formatDH(shipping)} />
            {order.discount_total > 0 ? <Row label="Remises" value={`− ${formatDH(order.discount_total)}`} /> : null}
            <div className="flex items-center justify-between border-t border-slate-100 pt-1.5">
                <span className="font-semibold text-slate-700">Total</span>
                <span className="text-base font-extrabold text-slate-900">{formatDH(total)}</span>
            </div>
            <Row label="Déjà payé" value={formatDH(paidAmount)} />
            <Row label="Paiement" value={order.payment_label || full?.payment_label || '—'} />
            <div className="flex items-center justify-between">
                <span className="font-semibold text-slate-700">À encaisser</span>
                <span className="font-bold text-blue-700">{formatDH(due)}</span>
            </div>
        </div>
    );
}

function Notes({ order, onSave, busy }) {
    const [shopifyNote, setShopifyNote] = useState(order.note || '');
    const [internal, setInternal] = useState(order.internal_note || '');
    const [confirmation, setConfirmation] = useState(order.confirmation_note || '');
    useEffect(() => {
        setShopifyNote(order.note || '');
        setInternal(order.internal_note || '');
        setConfirmation(order.confirmation_note || '');
    }, [order.id, order.note, order.internal_note, order.confirmation_note]);
    const dirty = shopifyNote !== (order.note || '') || internal !== (order.internal_note || '') || confirmation !== (order.confirmation_note || '');
    return (
        <div className="space-y-3">
            <Field label="Note Shopify / client" hint="Envoyée à Shopify pour une commande connectée.">
                <Textarea value={shopifyNote} onChange={(e) => setShopifyNote(e.target.value)} placeholder="Note client" />
            </Field>
            <Field label="Note interne" hint="Privée Lav'Fast Flow — ne modifie pas Shopify">
                <Textarea value={internal} onChange={(e) => setInternal(e.target.value)} placeholder="Ajouter une note interne…" />
            </Field>
            <Field label="Note de confirmation">
                <Textarea value={confirmation} onChange={(e) => setConfirmation(e.target.value)} placeholder="Commentaire de confirmation" />
            </Field>
            <Button size="sm" variant="secondary" disabled={busy || !dirty} onClick={() => onSave({ note: shopifyNote, internal_note: internal, confirmation_note: confirmation })}>
                Enregistrer les notes
            </Button>
        </div>
    );
}

function Contact({ order, onLogCall }) {
    const tel = telUrl(order.phone);
    const [wa, setWa] = useState(false);
    async function openWhatsApp() {
        setWa(true);
        try {
            const { data } = await window.axios.get(`/api/whatsapp/orders/${order.id}/conversation`);
            window.location.href = data?.path || `/whatsapp?search=${encodeURIComponent(order.phone)}`;
        } catch {
            window.location.href = `/whatsapp?search=${encodeURIComponent(order.phone)}`;
        }
    }
    const btn = 'inline-flex h-10 items-center justify-center gap-1.5 rounded-xl text-sm font-bold disabled:opacity-50';
    return (
        <div className="space-y-2">
            <div className="text-sm">
                <div className="font-bold text-slate-900">{order.customer_name || '—'}</div>
                <div className="font-mono text-slate-700">{order.phone || '—'}</div>
                <div className="text-slate-600">{order.city || '—'} · {order.address || '—'}</div>
                {order.email ? <div className="text-slate-500">{order.email}</div> : null}
                {order.client_history ? (
                    <p className="mt-1 text-xs text-slate-500">
                        {order.client_history.previous} commande(s) précédente(s) · {order.client_history.delivered} livrée(s) · {order.client_history.cancelled} annulée(s)/refusée(s) · {order.client_history.returned} retour(s)
                    </p>
                ) : null}
            </div>
            <div className="grid grid-cols-2 gap-2">
                {tel ? (
                    <a href={tel} onClick={onLogCall} className={`${btn} bg-blue-600 text-white`}>
                        <Phone className="h-4 w-4" /> Appeler
                    </a>
                ) : (
                    <button type="button" disabled className={`${btn} bg-slate-100 text-slate-400`}>
                        <Phone className="h-4 w-4" /> Appeler
                    </button>
                )}
                <button type="button" onClick={openWhatsApp} disabled={!order.phone || wa} className={`${btn} bg-emerald-600 text-white`}>
                    <MessageCircle className="h-4 w-4" /> {wa ? 'Ouverture…' : 'WhatsApp'}
                </button>
                <Link to={`/whatsapp?search=${encodeURIComponent(order.phone || '')}`} className={`${btn} border border-slate-200 bg-white text-slate-700 hover:bg-slate-50`}>
                    <Inbox className="h-4 w-4" /> Boîte de réception
                </Link>
                <button type="button" disabled title="Téléphonie VoIP : bientôt disponible" className={`${btn} border border-dashed border-slate-200 bg-white text-slate-400`}>
                    <Headphones className="h-4 w-4" /> VoIP (bientôt)
                </button>
            </div>
            <Button className="w-full" variant="secondary" onClick={onLogCall}>
                <PhoneCall className="h-4 w-4" /> Enregistrer l’appel
            </Button>
        </div>
    );
}

function Calls({ calls = [] }) {
    if (!calls.length) return <p className="text-sm text-slate-400">Aucun appel enregistré.</p>;
    return (
        <ul className="space-y-1.5">
            {calls.map((c) => {
                const r = CALL_RESULTS.find(([k]) => k === c.result);
                return (
                    <li key={c.id} className="rounded-xl border border-slate-100 px-3 py-1.5 text-sm">
                        <div className="flex items-center justify-between gap-2">
                            <span className={`rounded-md px-1.5 py-0.5 text-[11px] font-semibold ${r?.[2] || 'bg-slate-100 text-slate-600'}`}>{c.result_label}</span>
                            <span className="text-[11px] text-slate-400">{formatHistoryDate(c.called_at)}</span>
                        </div>
                        <div className="mt-0.5 text-xs text-slate-500">
                            {c.user_name || '—'} · {c.channel_label}
                            {c.duration_seconds ? ` · ${Math.round(c.duration_seconds / 60)} min` : ''}
                        </div>
                        {c.note ? <div className="text-xs text-slate-700">{c.note}</div> : null}
                    </li>
                );
            })}
        </ul>
    );
}

function Discounts({ order, canRemove, onRemove }) {
    const list = order.discounts || [];
    if (!list.length) return <p className="text-sm text-slate-400">Aucune remise.</p>;
    return (
        <ul className="space-y-1.5">
            {list.map((d) => (
                <li key={d.id} className={`flex items-start justify-between gap-2 rounded-xl border border-slate-100 px-3 py-1.5 text-sm ${d.removed_at ? 'opacity-50' : ''}`}>
                    <div className="min-w-0">
                        <div className="font-semibold text-slate-800">
                            <Tag className="mr-1 inline h-3.5 w-3.5 text-pink-600" />− {formatDH(d.amount)} {d.type === 'percent' ? `(${d.value} %)` : ''}
                            {d.removed_at ? <span className="ml-1 text-[11px] font-medium text-slate-500">retirée</span> : null}
                        </div>
                        <div className="text-[11px] text-slate-500">
                            {d.user_name} · {formatHistoryDate(d.created_at)}
                            {d.reason ? ` · ${d.reason}` : ''}
                        </div>
                    </div>
                    {canRemove && !d.removed_at ? (
                        <button type="button" onClick={() => onRemove(d)} className="text-slate-400 hover:text-rose-600" aria-label="Retirer la remise">
                            <Trash2 className="h-4 w-4" />
                        </button>
                    ) : null}
                </li>
            ))}
        </ul>
    );
}

function Actions({ canAct, busy, onConfirm, onNoAnswer, onPostpone, onCancel, onChangeStatus, onOrderCancel, mobile = false }) {
    const [channel, setChannel] = useState('phone');
    if (!canAct) {
        return (
            <div className="space-y-2">
                <p className="text-xs text-slate-500">Les boutons rapides sont masqués pour un statut final. Le statut actuel peut encore être changé.</p>
                <button type="button" disabled={busy} onClick={onChangeStatus} className="inline-flex h-10 w-full items-center justify-center gap-1.5 rounded-xl border border-slate-200 bg-white text-sm font-bold text-slate-700">
                    <ChevronDown className="h-4 w-4" /> Changer le statut ▾
                </button>
            </div>
        );
    }
    const btn = `inline-flex ${mobile ? 'h-11' : 'h-10'} items-center justify-center gap-1.5 rounded-xl text-sm font-bold disabled:opacity-50`;
    return (
        <div className="space-y-2">
            {!mobile ? (
                <Select value={channel} onChange={(e) => setChannel(e.target.value)} aria-label="Canal de confirmation">
                    <option value="phone">Confirmée par téléphone</option>
                    <option value="whatsapp">Confirmée par WhatsApp</option>
                </Select>
            ) : null}
            <div className={`grid gap-2 ${mobile ? 'grid-cols-4' : 'grid-cols-2'}`}>
                <button type="button" disabled={busy} onClick={() => onConfirm(channel)} className={`${btn} bg-emerald-600 text-white`}>
                    <CheckCircle2 className="h-4 w-4" /> <span className={mobile ? 'sr-only sm:not-sr-only' : ''}>Confirmer</span>
                </button>
                <button type="button" disabled={busy} onClick={onNoAnswer} className={`${btn} bg-orange-500 text-white`}>
                    <PhoneOff className="h-4 w-4" /> <span className={mobile ? 'sr-only sm:not-sr-only' : ''}>Pas de réponse</span>
                </button>
                <button type="button" disabled={busy} onClick={onPostpone} className={`${btn} border border-violet-200 bg-violet-50 text-violet-700`}>
                    <Clock3 className="h-4 w-4" /> <span className={mobile ? 'sr-only sm:not-sr-only' : ''}>Reporter</span>
                </button>
                <button type="button" disabled={busy} onClick={onCancel} title="Annuler (confirmation) : change seulement le statut de confirmation. L’annulation de la commande (Shopify / livraison) est dans le menu ⋯." className={`${btn} border border-rose-200 bg-rose-50 text-rose-700`}>
                    <XCircle className="h-4 w-4" /> <span className={mobile ? 'sr-only' : ''}>Annuler (confirmation)</span>
                </button>
            </div>
            {mobile ? <div className="grid grid-cols-4 text-center text-[10px] font-semibold text-slate-500"><span>Confirmer</span><span>Pas de rép.</span><span>Reporter</span><span>Annuler</span></div> : null}
            <div className="flex gap-2">
                <button type="button" disabled={busy} onClick={onChangeStatus} className="inline-flex h-10 flex-1 items-center justify-center gap-1.5 rounded-xl border border-slate-200 bg-white text-sm font-bold text-slate-700">
                    <ChevronDown className="h-4 w-4" /> Changer le statut ▾
                </button>
                {onOrderCancel ? (
                    <button type="button" disabled={busy} onClick={onOrderCancel} title="Annuler la commande : annule la commande elle-même (livraison / Shopify). Ce n’est pas le statut de confirmation." className="inline-flex h-10 items-center justify-center gap-1 rounded-xl border border-slate-200 px-3 text-sm font-bold text-slate-600" aria-label="Annuler la commande">
                        <MoreHorizontal className="h-4 w-4" />
                    </button>
                ) : null}
            </div>
        </div>
    );
}

function Modal({ title, onClose, children }) {
    return (
        <div className="fixed inset-0 z-[70] flex items-end justify-center bg-slate-900/50 sm:items-center sm:p-4" role="dialog" aria-label={title}>
            <button type="button" className="absolute inset-0 cursor-default" aria-label="Fermer" onClick={onClose} />
            <div className="relative z-10 w-full max-w-md rounded-t-2xl bg-white p-5 shadow-xl sm:rounded-2xl">
                <div className="mb-4 flex items-center justify-between gap-3">
                    <h3 className="text-base font-bold text-slate-900">{title}</h3>
                    <button type="button" onClick={onClose} className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100" aria-label="Fermer">
                        <X className="h-4 w-4" />
                    </button>
                </div>
                {children}
            </div>
        </div>
    );
}

function PostponeModal({ busy, onClose, onSubmit }) {
    const [date, setDate] = useState('');
    const [time, setTime] = useState('');
    const [note, setNote] = useState('');
    const [err, setErr] = useState('');
    function submit() {
        const at = new Date(`${date}T${time}`);
        if (!date || !time || Number.isNaN(at.getTime())) return setErr('Indiquez la date et l’heure du rappel.');
        if (at <= new Date()) return setErr('Le rappel doit être dans le futur.');
        onSubmit({ recall_at: at.toISOString(), note: note.trim() || undefined });
    }
    return (
        <Modal title="Reporter la commande" onClose={onClose}>
            <div className="space-y-3">
                <div className="grid grid-cols-2 gap-3">
                    <Field label="Date du rappel *">
                        <Input type="date" value={date} onChange={(e) => setDate(e.target.value)} />
                    </Field>
                    <Field label="Heure du rappel *">
                        <Input type="time" value={time} onChange={(e) => setTime(e.target.value)} />
                    </Field>
                </div>
                <Field label="Commentaire" hint="La commande revient automatiquement dans la file à cette heure.">
                    <Textarea rows={2} value={note} onChange={(e) => setNote(e.target.value)} placeholder="Ex. client occupé, rappeler après 18h" />
                </Field>
                {err ? <p className="text-sm font-medium text-rose-600">{err}</p> : null}
                <Button className="w-full" disabled={busy} onClick={submit}>
                    Valider le report
                </Button>
            </div>
        </Modal>
    );
}

function CancelModal({ busy, onClose, onSubmit }) {
    const [reason, setReason] = useState('');
    const [comment, setComment] = useState('');
    return (
        <Modal title="Annuler (confirmation)" onClose={onClose}>
            <div className="space-y-3">
                <Field label="Motif *">
                    <Textarea rows={2} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Ex. client refuse, doublon, adresse invalide…" />
                </Field>
                <Field label="Commentaire">
                    <Textarea rows={2} value={comment} onChange={(e) => setComment(e.target.value)} />
                </Field>
                <Button className="w-full" variant="danger" disabled={busy || reason.trim().length < 3} onClick={() => onSubmit({ reason: reason.trim(), comment: comment.trim() || undefined })}>
                    Confirmer l’annulation
                </Button>
            </div>
        </Modal>
    );
}

function StatusModal({ statuses, products, busy, onClose, onSubmit }) {
    const [code, setCode] = useState('');
    const [query, setQuery] = useState('');
    const [reason, setReason] = useState('');
    const [comment, setComment] = useState('');
    const [date, setDate] = useState('');
    const [time, setTime] = useState('');
    const [product, setProduct] = useState('');
    const [restock, setRestock] = useState('');
    const status = statuses.find((item) => item.code === code);
    const visible = statuses.filter((item) => `${item.name} ${item.category_label || ''}`.toLowerCase().includes(query.trim().toLowerCase()));
    function submit() {
        const payload = { status_code: code };
        if (status?.requires_reason) payload.reason = reason;
        if (status?.requires_comment || comment) payload.comment = comment;
        if (status?.requires_recall_date || date) payload.recall_at = date;
        if (status?.requires_time || time) payload.recall_time = time;
        if (status?.requires_product) payload.product_line_key = product;
        if (restock) payload.expected_restock_date = restock;
        onSubmit(payload);
    }
    return (
        <Modal title="Changer le statut" onClose={onClose}>
            <div className="space-y-3">
                <Input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Rechercher un statut…" />
                <Select value={code} onChange={(e) => setCode(e.target.value)} aria-label="Statut">
                    <option value="">Choisir…</option>
                    {visible.map((item) => (
                        <option key={item.code} value={item.code}>{item.name}</option>
                    ))}
                </Select>
                {status?.requires_recall_date ? (
                    <Field label="Date de rappel *"><Input type="date" value={date} onChange={(e) => setDate(e.target.value)} /></Field>
                ) : null}
                {status?.requires_time ? (
                    <Field label="Heure *"><Input type="time" value={time} onChange={(e) => setTime(e.target.value)} /></Field>
                ) : null}
                {status?.requires_reason ? (
                    <Field label="Motif *">
                        {(status.reason_options || []).length ? (
                            <Select value={reason} onChange={(e) => setReason(e.target.value)}>
                                <option value="">Choisir…</option>
                                {status.reason_options.map((opt) => <option key={opt} value={opt}>{opt}</option>)}
                            </Select>
                        ) : (
                            <Input value={reason} onChange={(e) => setReason(e.target.value)} />
                        )}
                    </Field>
                ) : null}
                {status?.requires_comment ? (
                    <Field label="Commentaire *"><Textarea rows={2} value={comment} onChange={(e) => setComment(e.target.value)} /></Field>
                ) : null}
                {status?.requires_product ? (
                    <Field label="Produit concerné *">
                        <Select value={product} onChange={(e) => setProduct(e.target.value)}>
                            <option value="">Choisir…</option>
                            {products.map((line) => (
                                <option key={line.key || line.id} value={line.key || line.id}>{line.title}</option>
                            ))}
                        </Select>
                    </Field>
                ) : null}
                {status?.category === 'probleme_stock' ? (
                    <Field label="Réapprovisionnement prévu"><Input type="date" value={restock} onChange={(e) => setRestock(e.target.value)} /></Field>
                ) : null}
                <Button className="w-full" disabled={busy || !code} onClick={submit}>Enregistrer le statut</Button>
            </div>
        </Modal>
    );
}

function CallModal({ busy, onClose, onSubmit }) {
    const [result, setResult] = useState('');
    const [channel, setChannel] = useState('phone');
    const [note, setNote] = useState('');
    return (
        <Modal title="Enregistrer l’appel" onClose={onClose}>
            <div className="space-y-3">
                <div className="grid grid-cols-2 gap-2" role="radiogroup" aria-label="Résultat">
                    {CALL_RESULTS.map(([k, label]) => (
                        <button key={k} type="button" role="radio" aria-checked={result === k} onClick={() => setResult(k)} className={`rounded-xl border px-3 py-2 text-sm font-semibold ${result === k ? 'border-blue-300 bg-blue-50 text-blue-700 ring-2 ring-blue-100' : 'border-slate-200 text-slate-700 hover:bg-slate-50'}`}>
                            {label}
                        </button>
                    ))}
                </div>
                <Field label="Canal">
                    <Select value={channel} onChange={(e) => setChannel(e.target.value)}>
                        <option value="phone">Téléphone</option>
                        <option value="whatsapp">WhatsApp</option>
                    </Select>
                </Field>
                <Field label="Note">
                    <Textarea rows={2} value={note} onChange={(e) => setNote(e.target.value)} />
                </Field>
                <p className="text-[11px] text-slate-400">Date, heure et agent sont enregistrés automatiquement. La durée sera remplie par la téléphonie VoIP.</p>
                <Button className="w-full" disabled={busy || !result} onClick={() => onSubmit({ result, channel, note: note.trim() || undefined })}>
                    Enregistrer
                </Button>
            </div>
        </Modal>
    );
}

function DiscountModal({ busy, total, onClose, onSubmit }) {
    const [type, setType] = useState('amount');
    const [value, setValue] = useState('');
    const [reason, setReason] = useState('');
    const v = Number(value) || 0;
    const amount = type === 'percent' ? (Number(total || 0) * v) / 100 : v;
    return (
        <Modal title="Ajouter une remise" onClose={onClose}>
            <div className="space-y-3">
                <div className="grid grid-cols-2 gap-3">
                    <Field label="Type">
                        <Select value={type} onChange={(e) => setType(e.target.value)}>
                            <option value="amount">Montant (DH)</option>
                            <option value="percent">Pourcentage (%)</option>
                        </Select>
                    </Field>
                    <Field label="Valeur">
                        <Input type="number" min="0" step="0.01" value={value} onChange={(e) => setValue(e.target.value)} />
                    </Field>
                </div>
                <Field label="Motif">
                    <Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Ex. geste commercial" />
                </Field>
                <p className="text-sm text-slate-600">
                    Nouveau total : <strong>{formatDH(Math.max(0, Number(total || 0) - amount))}</strong> <span className="text-xs text-slate-400">(interne, non envoyé à Shopify)</span>
                </p>
                <Button className="w-full" disabled={busy || v <= 0} onClick={() => onSubmit({ type, value: v, reason: reason.trim() || undefined })}>
                    Ajouter la remise
                </Button>
            </div>
        </Modal>
    );
}
