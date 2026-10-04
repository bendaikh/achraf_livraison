import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, Bike } from 'lucide-react';
import api, { errorMessage } from '../lib/api';
import { useMeta } from '../context/MetaContext';
import { formatDH, formatDateTime, formatDate } from '../lib/format';
import { Alert, Button, Card, EmptyState, Spinner } from '../components/ui';
import LocalAssignDrawer from '../components/orders/LocalAssignDrawer';
import OrderItemsEditor from '../components/orders/OrderItemsEditor';
import { BlockedClientAlert } from '../components/clients/ClientBadges';
import OrderSavSection from '../components/sav/OrderSavSection';
import { useAuth } from '../contexts/AuthContext';
import { ColorBadge, StatusBadge } from '../components/ui/Badge';
import StatusChangeForm from '../components/orders/StatusChangeForm';
import SpeedafOrderCard from '../components/orders/SpeedafOrderCard';
import OzonOrderCard from '../components/ozon/OzonOrderCard';
import SiftOrderCard from '../components/sift/SiftOrderCard';

const HISTORY_KINDS = { confirmation: 'Confirmation', affectation: 'Affectation', produits: 'Produits', expedition: 'Expédition', appel: 'Appel', remise: 'Remise', agent: 'Agent', sav: 'SAV', ozon: 'Ozon Express', sift: 'Sift' };

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
    const [assigning, setAssigning] = useState(false);
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
                <StatusBadge status={order.delivery_status} />
                {confirmation ? <ColorBadge color={confirmation.color} label={confirmation.label} /> : null}
            </div>
            <Alert>{error}</Alert>
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
                            <Info label="Paiement">{meta.paymentMap[order.payment_method]?.label || order.payment_method}</Info>
                            <Info label="Source">{order.source}</Info>
                            <Info label="Utilisateur assigné">{order.assigned_user?.name}</Info>
                            <Info label="Date">{formatDateTime(order.created_at)}</Info>
                            {order.postponed_at ? <Info label="Reportée au">{order.postponed_at}</Info> : null}
                            {order.status_reason ? <Info label="Motif">{order.status_reason}</Info> : null}
                            {order.collected_amount !== null ? <Info label="Montant encaissé">{formatDH(order.collected_amount)}</Info> : null}
                            {order.delivered_at ? <Info label="Livrée le">{formatDateTime(order.delivered_at)}</Info> : null}
                        </div>
                        {order.note ? <p className="mt-4 rounded-xl bg-slate-50 p-3 text-sm text-slate-600">{order.note}</p> : null}
                    </Card>

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

                    <Card title="Livraison locale">
                        {order.driver ? (
                            <div className="mb-3 flex items-center gap-3">
                                <span className="flex h-9 w-9 items-center justify-center rounded-full bg-blue-50 text-blue-600">
                                    <Bike className="h-4 w-4" />
                                </span>
                                <div className="min-w-0">
                                    <div className="font-semibold text-slate-800">{order.driver.name}</div>
                                    <div className="text-xs text-slate-500">
                                        {order.assigned_at ? `Affectée le ${formatDateTime(order.assigned_at)}` : 'Livreur assigné'}
                                        {order.assigned_by_name ? ` par ${order.assigned_by_name}` : ''}
                                    </div>
                                </div>
                            </div>
                        ) : (
                            <p className="mb-3 text-sm text-slate-500">Aucun livreur local.</p>
                        )}
                        {can('orders.assign_driver') ? (
                            <div className="flex flex-wrap gap-2">
                                <Button size="sm" onClick={() => setAssigning(true)} disabled={busy || (!order.driver && !!order.shipment)} title={!order.driver && order.shipment ? `Déjà envoyée à ${order.shipment.carrier_label}` : ''}>
                                    <Bike className="h-3.5 w-3.5" /> {order.driver ? 'Réaffecter' : 'Affecter à livraison locale'}
                                </Button>
                                {order.driver ? (
                                    <Button
                                        size="sm"
                                        variant="secondary"
                                        disabled={busy}
                                        onClick={() => window.confirm('Retirer le livreur de cette commande ?') && act(() => api.put(`/orders/${order.id}`, { driver_id: null }))}
                                    >
                                        Retirer le livreur
                                    </Button>
                                ) : null}
                            </div>
                        ) : (
                            <p className="text-xs text-slate-400">Vous n’avez pas le droit d’affecter des livreurs.</p>
                        )}
                        <p className="mt-2 text-[11px] text-slate-400">L’affectation crée la mission de livraison avec le tarif actuel du livreur.</p>
                    </Card>
                    <LocalAssignDrawer
                        open={assigning}
                        onClose={() => setAssigning(false)}
                        orderIds={[order.id]}
                        currentDriverId={order.driver_id}
                        onDone={() => load()}
                    />

                    <OzonOrderCard order={order} onChanged={setOrder} />
                    <SiftOrderCard order={order} onChanged={setOrder} />

                    <SpeedafOrderCard order={order} onChanged={setOrder} />

                    <Card title="Statut de livraison">
                        <div className="mb-3 flex items-center gap-2 text-sm text-slate-500">
                            Statut actuel : <StatusBadge status={order.delivery_status} />
                        </div>
                        <StatusChangeForm order={order} onChanged={setOrder} />
                    </Card>
                </div>
            </div>
        </div>
    );
}
