import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, MoreHorizontal } from 'lucide-react';
import api, { errorMessage } from '../lib/api';
import { useMeta } from '../context/MetaContext';
import { formatDH, formatDateTime, formatDate } from '../lib/format';
import { Alert, Button, Card, EmptyState, Spinner } from '../components/ui';
import OrderItemsEditor from '../components/orders/OrderItemsEditor';
import { BlockedClientAlert } from '../components/clients/ClientBadges';
import OrderSavSection from '../components/sav/OrderSavSection';
import { useAuth } from '../contexts/AuthContext';
import { ColorBadge, StatusBadge } from '../components/ui/Badge';
import StatusChangeForm from '../components/orders/StatusChangeForm';
import DeliveryBlock from '../components/delivery/DeliveryBlock';
import SyncStatusBadge from '../components/shopify/SyncStatusBadge';
import OrderForm from '../components/orders/OrderForm';
import OrderCreate from '../components/orders/OrderCreate';
import { CancelOrderDialog, DeleteDraftDialog } from '../components/orders/OrderLifecycleDialogs';

const HISTORY_KINDS = { confirmation: 'Confirmation', affectation: 'Affectation', produits: 'Produits', expedition: 'Expédition', appel: 'Appel', remise: 'Remise', agent: 'Agent', sav: 'SAV', ozon: 'Ozon Express', sift: 'Sift', shopify: 'Shopify', commande: 'Commande' };

function Info({ label, children }) {
    return (
        <div className="min-w-0">
            <div className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{label}</div>
            <div className="mt-0.5 break-words text-sm font-semibold text-slate-800">{children || '—'}</div>
        </div>
    );
}

export default function OrderDetail() {
    const { id } = useParams();
    const meta = useMeta();
    const [order, setOrder] = useState(null);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);
    const [editing, setEditing] = useState(false);
    const [menu, setMenu] = useState(false);
    const [canceling, setCanceling] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [resuming, setResuming] = useState(false);
    const { can } = useAuth();

    const load = useCallback(async () => {
        try {
            const { data } = await api.get(`/orders/${id}`);
            setOrder(data.data);
        } catch (e) {
            setError(errorMessage(e, 'Commande introuvable.'));
        }
    }, [id]);

    useEffect(() => {
        load();
    }, [load]);

    async function act(fn) {
        setBusy(true);
        setError(null);
        try {
            const { data } = await fn();
            setOrder(data.data);
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy(false);
        }
    }

    if (!order) return error ? <Alert>{error}</Alert> : <Spinner />;

    const confirmation = meta.confirmationMap[order.confirmation_status];

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-3">
                <Link to="/commandes" className="inline-flex items-center gap-1 text-sm font-semibold text-blue-600 hover:text-blue-700">
                    <ArrowLeft className="h-4 w-4" /> Commandes
                </Link>
                <h1 className="text-xl font-bold text-slate-900 sm:text-2xl">{order.reference}</h1>
                <SyncStatusBadge
                    status={order.shopify_sync_status}
                    error={order.shopify_sync_error}
                    journalHref="#historique"
                    onRetry={order.shopify_sync_status === 'failed' && (order.creation_key ? can('orders.create') : can('orders.edit_items')) ? async () => {
                        setError(null);
                        try {
                            await api.post(order.creation_key ? `/orders/${order.id}/flow-retry` : `/orders/${order.id}/shopify-retry`);
                            await load();
                        } catch (e) {
                            setError(errorMessage(e));
                        }
                    } : undefined}
                />
                <StatusBadge status={order.delivery_status} />
                {confirmation ? <ColorBadge color={confirmation.color} label={confirmation.label} /> : null}
                {order.shopify_sync_status === 'conflict' && order.items_edited_at && can('orders.edit_items') ? (
                    <Button
                        size="sm"
                        variant="secondary"
                        disabled={busy}
                        onClick={async () => {
                            setBusy(true);
                            setError(null);
                            try {
                                await api.post(`/orders/${order.id}/shopify-take-remote`);
                                await load();
                            } catch (e) {
                                setError(errorMessage(e));
                            } finally {
                                setBusy(false);
                            }
                        }}
                    >
                        Reprendre la version Shopify
                    </Button>
                ) : null}
                {can('orders.edit') ? <Button size="sm" variant="secondary" onClick={() => setEditing(true)}>Modifier</Button> : null}
                {order.is_draft && can('orders.create') ? <Button size="sm" onClick={() => setResuming(true)}>Compléter le brouillon</Button> : null}
                <div className="relative">
                    <Button size="sm" variant="ghost" aria-label="Actions" aria-expanded={menu} onClick={() => setMenu((v) => !v)}>
                        <MoreHorizontal className="h-4 w-4" />
                    </Button>
                    {menu ? (
                        <div className="absolute right-0 z-20 mt-1 w-56 rounded-xl border border-slate-200 bg-white py-1 text-sm shadow-lg">
                            {order.can_cancel ? (
                                <button type="button" className="block w-full px-3 py-2 text-left hover:bg-slate-50" onClick={() => { setMenu(false); setCanceling(true); }}>Annuler la commande</button>
                            ) : null}
                            {order.can_delete_draft ? (
                                <button type="button" className="block w-full px-3 py-2 text-left text-rose-700 hover:bg-rose-50" onClick={() => { setMenu(false); setDeleting(true); }}>Supprimer le brouillon</button>
                            ) : null}
                            {(order.cancel_blockers || []).map((reason) => (
                                <p key={reason} className="px-3 py-2 text-xs text-slate-500">{reason}</p>
                            ))}
                        </div>
                    ) : null}
                </div>
            </div>
            <Alert>{error}</Alert>
            {order.flow_notice ? <div className="rounded-xl bg-amber-50 px-3 py-2 text-sm font-medium text-amber-900">{order.flow_notice}</div> : null}
            {order.is_draft ? <div className="rounded-xl bg-amber-100 px-3 py-2 text-sm font-bold text-amber-900">Brouillon</div> : null}
            <BlockedClientAlert block={order.client_blocked} />

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Card title="Informations de la commande">
                        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                            <Info label="Client">
                                {order.client_key && can('clients.view') ? (
                                    <Link to={`/clients/${order.client_key}`} className="text-blue-700 hover:underline">
                                        {order.customer_name}
                                    </Link>
                                ) : (
                                    order.customer_name
                                )}
                            </Info>
                            <Info label="Téléphone">{order.customer_phone}</Info>
                            <Info label="Ville">{order.city}</Info>
                            <Info label="Adresse">{order.address}</Info>
                            <Info label="Montant">{formatDH(order.amount)}</Info>
                            <Info label="À encaisser">À encaisser : {formatDH(order.amount_due ?? 0)}</Info>
                            <Info label="Paiement">{order.payment_label || meta.paymentMap[order.payment_method]?.label || order.payment_method}</Info>
                            <Info label="Source">{order.source}</Info>
                            <Info label="Créée par">{order.created_by_name}</Info>
                            <Info label="Commercial">{order.commercial?.name}</Info>
                            <Info label="Utilisateur assigné">{order.assigned_user?.name}</Info>
                            {order.shopify_admin_url ? (
                                <Info label="Shopify">
                                    <a href={order.shopify_admin_url} target="_blank" rel="noreferrer" className="text-blue-700 hover:underline">{order.reference}</a>
                                </Info>
                            ) : null}
                            {order.cancel_reason_label ? <Info label="Annulation">{order.cancel_reason_label}{order.cancel_comment ? ` — ${order.cancel_comment}` : ''}</Info> : null}
                            <Info label="Date">{formatDateTime(order.created_at)}</Info>
                            {order.postponed_at ? <Info label="Reportée au">{order.postponed_at}</Info> : null}
                            {order.status_reason ? <Info label="Motif">{order.status_reason}</Info> : null}
                            {order.collected_amount !== null ? <Info label="Montant encaissé">{formatDH(order.collected_amount)}</Info> : null}
                            {order.delivered_at ? <Info label="Livrée le">{formatDateTime(order.delivered_at)}</Info> : null}
                        </div>
                        {order.note ? <p className="mt-4 rounded-xl bg-slate-50 p-3 text-sm text-slate-600">{order.note}</p> : null}
                    </Card>

                    {order.shopify_order_id ? (
                        <Card title="Shopify" subtitle={order.shopify?.shop_domain || 'Boutique connectée'}>
                            <div className="space-y-3 text-sm">
                                {(order.fulfillments || []).length ? (
                                    <ul className="divide-y divide-slate-100 rounded-xl border border-slate-100">
                                        {order.fulfillments.map((f) => (
                                            <li key={f.id} className="px-3 py-2">
                                                <span className="font-semibold text-slate-800">{f.tracking_number || 'Sans numéro'}</span>
                                                <span className="text-slate-500"> · {f.tracking_company || f.status || '—'}</span>
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p className="text-slate-500">Aucun fulfillment Shopify.</p>
                                )}
                                {can('orders.ship') ? (
                                    <Button
                                        disabled={busy}
                                        onClick={async () => {
                                            const number = window.prompt('Numéro de suivi à envoyer à Shopify');
                                            if (!number) return;
                                            setBusy(true);
                                            setError(null);
                                            try {
                                                await api.post(`/orders/${order.id}/shopify-tracking`, {
                                                    tracking_number: number,
                                                    notify_customer: false,
                                                });
                                                await load();
                                            } catch (e) {
                                                setError(errorMessage(e));
                                            } finally {
                                                setBusy(false);
                                            }
                                        }}
                                    >
                                        Envoyer le suivi à Shopify
                                    </Button>
                                ) : null}
                            </div>
                        </Card>
                    ) : null}

                    <Card title="Produits" subtitle={`${order.quantity} article(s)`}>
                        <OrderItemsEditor order={order} onChanged={setOrder} />
                    </Card>

                    {can('sav.manage') ? <OrderSavSection order={order} onChanged={load} /> : null}
                    <Card title="Missions liées" bodyClassName="p-0">
                        {order.missions?.length ? (
                            <ul className="divide-y divide-slate-100">
                                {order.missions.map((m) => (
                                    <li key={m.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm sm:px-5">
                                        <div>
                                            <div className="font-semibold text-slate-800">
                                                {m.reference} · {meta.missionTypeMap[m.type]?.label}
                                            </div>
                                            <div className="text-xs text-slate-500">
                                                {m.driver?.name || 'Sans livreur'} · {formatDate(m.scheduled_date)}
                                                {m.driver_price !== null && m.driver_price !== undefined ? ` · Tarif livreur : ${formatDH(m.driver_price)}` : ''}
                                            </div>
                                        </div>
                                        <ColorBadge
                                            color={meta.missionStatusMap[m.status]?.color}
                                            label={meta.missionStatusMap[m.status]?.label || m.status}
                                        />
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <EmptyState>Aucune mission</EmptyState>
                        )}
                    </Card>

                    {order.histories ? (
                    <div id="historique">
                        <Card title="Historique des statuts" bodyClassName="p-0">
                            {order.histories.length ? (
                                <ol className="divide-y divide-slate-100">
                                    {order.histories.map((h) => (
                                        <li key={h.id} className="flex flex-wrap items-start justify-between gap-2 px-4 py-3 sm:px-5">
                                            <div className="min-w-0">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <ColorBadge color={h.status_color} label={h.status_name} />
                                                    {h.kind !== 'livraison' ? (
                                                        <span className="text-[11px] font-semibold uppercase text-slate-400">{HISTORY_KINDS[h.kind] || h.kind}</span>
                                                    ) : null}
                                                </div>
                                                <div className="mt-1 text-xs text-slate-500">
                                                    {[
                                                        h.from_status_name ? `Depuis : ${h.from_status_name}` : null,
                                                        h.data?.reason ? `Motif : ${h.data.reason}` : null,
                                                        h.data?.postponed_at ? `Reportée au ${h.data.postponed_at}` : null,
                                                        h.data?.collected_amount !== undefined && h.data?.collected_amount !== null
                                                            ? `Encaissé : ${formatDH(h.data.collected_amount)}`
                                                            : null,
                                                        h.note,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · ')}
                                                </div>
                                            </div>
                                            <div className="text-right text-xs text-slate-400">
                                                {formatDateTime(h.created_at)}
                                                {h.user_name || h.data?.actor ? <div>{h.user_name || h.data?.actor}</div> : null}
                                            </div>
                                        </li>
                                    ))}
                                </ol>
                            ) : (
                                <EmptyState>Aucun historique</EmptyState>
                            )}
                        </Card>
                    </div>
                    ) : null}
                </div>

                <div className="space-y-4">
                    <Card title="Confirmation">
                        <div className="flex flex-wrap gap-2">
                            {meta.confirmationStatuses.map((c) => (
                                <button
                                    key={c.value}
                                    type="button"
                                    disabled={busy || c.value === order.confirmation_status}
                                    onClick={() => act(() => api.post(`/orders/${order.id}/confirmation`, { confirmation_status: c.value }))}
                                    className="rounded-full px-3 py-1.5 text-xs font-semibold ring-1 ring-inset transition disabled:cursor-default"
                                    style={
                                        c.value === order.confirmation_status
                                            ? { backgroundColor: c.color, color: '#fff', '--tw-ring-color': c.color }
                                            : { color: c.color, '--tw-ring-color': `${c.color}55` }
                                    }
                                >
                                    {c.label}
                                </button>
                            ))}
                        </div>
                    </Card>

                    <DeliveryBlock
                        order={order}
                        onChanged={(saved) => {
                            if (saved && saved.id) setOrder(saved);
                            else load();
                        }}
                    />

                    <Card title="Statut de livraison">
                        <div className="mb-3 flex items-center gap-2 text-sm text-slate-500">
                            Statut actuel : <StatusBadge status={order.delivery_status} />
                        </div>
                        <StatusChangeForm order={order} onChanged={setOrder} />
                    </Card>
                </div>
            </div>
            <OrderForm
                open={editing}
                order={order}
                onClose={() => setEditing(false)}
                onSaved={(saved) => {
                    setEditing(false);
                    setOrder(saved);
                }}
            />
            <OrderCreate
                open={resuming}
                order={order}
                onClose={() => setResuming(false)}
                onSaved={(saved) => {
                    setResuming(false);
                    setOrder(saved);
                }}
            />
            {canceling ? (
                <CancelOrderDialog
                    orders={order}
                    onClose={() => setCanceling(false)}
                    onDone={({ single }) => {
                        setCanceling(false);
                        if (single?.data) setOrder(single.data);
                        else load();
                    }}
                />
            ) : null}
            {deleting ? (
                <DeleteDraftDialog
                    orders={order}
                    onClose={() => setDeleting(false)}
                    onDone={() => { window.location.assign('/commandes'); }}
                />
            ) : null}
        </div>
    );
}
