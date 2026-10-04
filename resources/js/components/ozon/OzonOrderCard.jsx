import { useState } from 'react';
import { FileText, History, RefreshCw, Search, Send, Truck } from 'lucide-react';
import api, { errorMessage } from '../../lib/api';
import { formatDateTime, formatDH } from '../../lib/format';
import useCarriers from '../../hooks/useCarriers';
import { useAuth } from '../../contexts/AuthContext';
import { Alert, Button, Card } from '../ui';
import CarrierSendDialog from './CarrierSendDialog';
import DocumentLinks from './DocumentLinks';

const STATE_LABEL = { created: 'Colis actif', in_transit: 'En cours', delivered: 'Livré', returned: 'Retourné', cancelled: 'Annulé' };

function Row({ label, children }) {
    return (
        <div className="flex justify-between gap-3 text-sm">
            <span className="text-slate-500">{label}</span>
            <span className="text-right font-medium text-slate-800">{children ?? '—'}</span>
        </div>
    );
}

function ShipmentBlock({ s, order, busy, act }) {
    const [showHistory, setShowHistory] = useState(false);
    const yes = (v) => (Number(v) === 1 ? 'Oui' : 'Non');
    return (
        <div className="space-y-2">
            <div className="rounded-xl bg-teal-50 px-3 py-2 ring-1 ring-teal-100">
                <div className="text-[11px] font-semibold uppercase tracking-wide text-teal-600">{s.kind === 'echange' ? 'Colis d’échange (SAV)' : 'N° de suivi Ozon'}</div>
                <div className="font-mono text-base font-bold text-slate-900">{s.tracking_number}</div>
                <div className="mt-1 text-sm font-semibold text-teal-700">{s.raw_status || 'Nouveau Colis'}</div>
                {s.raw_status_comment ? <div className="text-xs text-slate-600">{s.raw_status_comment}</div> : null}
                <div className="mt-0.5 text-[11px] text-slate-500">
                    {STATE_LABEL[s.state] || s.state}
                    {s.mapped_status ? ` · statut Lav’Fast : ${s.mapped_status_name || s.mapped_status}` : ''}
                    {s.status_at ? ` · ${formatDateTime(s.status_at)}` : ''}
                </div>
                {s.last_synced_at ? <div className="text-[11px] text-slate-400">Dernière synchro : {formatDateTime(s.last_synced_at)}</div> : null}
            </div>
            {s.last_error ? <div className="rounded-lg bg-rose-50 px-3 py-2 text-xs font-medium text-rose-700">{s.last_error}</div> : null}
            <div className="space-y-1">
                <Row label="Destinataire">{s.receiver}</Row>
                <Row label="Téléphone">{s.phone}</Row>
                <Row label="Ville Ozon">{s.city_name ? `${s.city_name} (#${s.city_id})` : null}</Row>
                <Row label="Adresse">{s.address}</Row>
                <Row label="Montant (COD)">{s.price !== null ? formatDH(s.price) : null}</Row>
                <Row label="Frais livré / retourné / refusé">
                    {[s.delivered_price, s.returned_price, s.refused_price].map((v) => (v === null ? '—' : formatDH(v))).join(' / ')}
                </Row>
                <Row label="Ouverture · Fragile · Échange">
                    {yes(s.parcel_open)} · {yes(s.parcel_fragile)} · {yes(s.parcel_replace)}
                </Row>
                <Row label="Type">{Number(s.parcel_stock) === 1 ? 'Stock Ozon' : 'Ramassage'}</Row>
            </div>
            {s.delivery_note ? (
                <div className="rounded-lg bg-slate-50 px-3 py-2">
                    <div className="text-xs font-semibold text-slate-700">
                        BL Ozon <span className="font-mono">{s.delivery_note.ref}</span> · {s.delivery_note.state === 'saved' ? 'enregistré' : 'non finalisé'}
                    </div>
                    {s.delivery_note.state === 'saved' ? (
                        <div className="mt-1">
                            <DocumentLinks documents={s.delivery_note.documents} compact />
                        </div>
                    ) : null}
                </div>
            ) : null}
            <div className="flex flex-wrap gap-2">
                <Button size="sm" variant="secondary" onClick={() => act('refresh', () => api.post(`/ozon/orders/${order.id}/refresh`, { shipment_id: s.id }))} disabled={!!busy}>
                    <RefreshCw className={`h-3.5 w-3.5 ${busy === 'refresh' ? 'animate-spin' : ''}`} /> Actualiser depuis Ozon
                </Button>
                <Button size="sm" variant="secondary" onClick={() => act('track', () => api.post(`/ozon/orders/${order.id}/track`, { shipment_id: s.id }))} disabled={!!busy}>
                    <Search className="h-3.5 w-3.5" /> Vérifier le suivi
                </Button>
                {!s.delivery_note && s.kind !== 'echange' ? (
                    <Button
                        size="sm"
                        variant="secondary"
                        onClick={() => window.confirm('Créer un BL Ozon pour cette commande ?') && act('bl', () => api.post('/ozon/delivery-notes', { order_ids: [order.id] }))}
                        disabled={!!busy}
                    >
                        <FileText className="h-3.5 w-3.5" /> Créer BL Ozon
                    </Button>
                ) : null}
            </div>
            {s.history?.length ? (
                <div>
                    <button type="button" onClick={() => setShowHistory((v) => !v)} className="inline-flex items-center gap-1 text-xs font-semibold text-slate-600 hover:text-slate-900">
                        <History className="h-3.5 w-3.5" /> {showHistory ? 'Masquer' : 'Voir'} l’historique Ozon ({s.history.length})
                    </button>
                    {showHistory ? (
                        <ol className="mt-1.5 space-y-1 border-l-2 border-teal-100 pl-3">
                            {s.history.map((h, i) => (
                                <li key={i} className="text-xs">
                                    <span className="font-semibold text-slate-800">{h.status}</span>
                                    {h.comment ? <span className="text-slate-500"> — {h.comment}</span> : null}
                                    {h.time ? <div className="text-[11px] text-slate-400">{h.time}</div> : null}
                                </li>
                            ))}
                        </ol>
                    ) : null}
                </div>
            ) : null}
        </div>
    );
}

/** Fiche commande → Ozon Express: send (validation popup), parcel details, refresh / tracking, BL. */
export default function OzonOrderCard({ order, onChanged }) {
    const { carriers } = useCarriers();
    const { can } = useAuth();
    const [busy, setBusy] = useState('');
    const [msg, setMsg] = useState(null);
    const [error, setError] = useState(null);
    const [dialog, setDialog] = useState(false);
    const carrier = carriers.find((c) => c.key === 'ozon');
    const shipments = order.ozon_shipments || [];
    const current = order.ozon;
    const others = shipments.filter((s) => s.id !== current?.id && s.tracking_number);

    if (!carrier || (!carrier.available && !shipments.length)) return null;

    async function act(name, fn) {
        setBusy(name);
        setMsg(null);
        setError(null);
        try {
            const { data } = await fn();
            setMsg(data.message);
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy('');
            const { data } = await api.get(`/orders/${order.id}`);
            onChanged(data.data);
        }
    }

    const otherCarrier = order.shipment && order.shipment.carrier !== 'ozon' ? order.shipment : null;

    return (
        <Card title="Ozon Express" actions={<Truck className="h-4 w-4 text-teal-600" />}>
            <div className="space-y-3">
                <Alert>{error}</Alert>
                <Alert type="success">{msg}</Alert>
                {current ? (
                    <ShipmentBlock s={current} order={order} busy={busy} act={act} />
                ) : otherCarrier ? (
                    <p className="text-sm text-slate-500">
                        Déjà envoyée à {otherCarrier.carrier_label} (n° {otherCarrier.tracking}).
                    </p>
                ) : (
                    <div className="space-y-2">
                        <p className="text-sm text-slate-500">Pas encore envoyée à Ozon Express.</p>
                        {can('orders.ship') ? (
                            <Button size="sm" onClick={() => setDialog(true)} disabled={!carrier.available} title={carrier.reason || ''} style={{ backgroundColor: carrier.color }}>
                                <Send className="h-3.5 w-3.5" /> Envoyer à Ozon
                            </Button>
                        ) : null}
                        {!carrier.available ? <p className="text-xs text-amber-700">{carrier.reason}</p> : null}
                    </div>
                )}
                {others.length ? (
                    <div className="space-y-3 border-t border-slate-100 pt-3">
                        <div className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Autres colis Ozon</div>
                        {others.map((s) => (
                            <ShipmentBlock key={s.id} s={s} order={order} busy={busy} act={act} />
                        ))}
                    </div>
                ) : null}
            </div>
            {dialog ? (
                <CarrierSendDialog
                    carrier={carrier}
                    orderIds={[order.id]}
                    onClose={() => setDialog(false)}
                    onDone={async () => {
                        const { data } = await api.get(`/orders/${order.id}`);
                        onChanged(data.data);
                    }}
                />
            ) : null}
        </Card>
    );
}
