import { useState } from 'react';
import { Ban, Download, EyeOff, History, Pencil, RefreshCw, Search, Send, Truck } from 'lucide-react';
import api, { errorMessage } from '../../lib/api';
import { formatDateTime, formatDH } from '../../lib/format';
import useCarriers from '../../hooks/useCarriers';
import { useAuth } from '../../contexts/AuthContext';
import { Alert, Button, Card, Field, Input, Select } from '../ui';
import CarrierSendDialog from '../ozon/CarrierSendDialog';
import AmountDueStaleNotice from '../orders/AmountDueStaleNotice';

const STATE_LABEL = { created: 'Colis actif', delivered: 'Livré', returned: 'Retourné', cancelled: 'Annulé' };

function Row({ label, children }) {
    return (
        <div className="flex justify-between gap-3 text-sm">
            <span className="text-slate-500">{label}</span>
            <span className="text-right font-medium text-slate-800">{children ?? '—'}</span>
        </div>
    );
}

function EditForm({ s, busy, onSubmit, onCancel }) {
    const [form, setForm] = useState({ receiver: s.receiver || '', phone: s.phone || '', address: s.address || '', city: s.city || '', cod_amount: s.cod_amount ?? '', notes: '' });
    const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));
    return (
        <form
            className="space-y-2 rounded-xl bg-slate-50 p-3"
            onSubmit={(e) => {
                e.preventDefault();
                const payload = Object.fromEntries(Object.entries(form).filter(([k, v]) => String(v) !== String(k === 'cod_amount' ? (s.cod_amount ?? '') : s[k] || '')));
                onSubmit(payload);
            }}
        >
            <div className="text-xs font-semibold text-slate-600">Modifier le colis (Sift n’accepte que les colis « En attente »)</div>
            <div className="grid gap-2 sm:grid-cols-2">
                <Field label="Nom">
                    <Input value={form.receiver} onChange={set('receiver')} maxLength={120} />
                </Field>
                <Field label="Téléphone">
                    <Input value={form.phone} onChange={set('phone')} maxLength={30} />
                </Field>
                <Field label="Ville">
                    <Input value={form.city} onChange={set('city')} maxLength={120} />
                </Field>
                <Field label="COD (DH)">
                    <Input type="number" min="0" step="0.01" value={form.cod_amount} onChange={set('cod_amount')} />
                </Field>
            </div>
            <Field label="Adresse">
                <Input value={form.address} onChange={set('address')} maxLength={250} />
            </Field>
            <Field label="Notes">
                <Input value={form.notes} onChange={set('notes')} maxLength={250} placeholder="Laisser vide pour ne pas modifier" />
            </Field>
            <div className="flex gap-2">
                <Button size="sm" type="submit" disabled={!!busy}>
                    {busy === 'edit' ? 'Envoi…' : 'Enregistrer chez Sift'}
                </Button>
                <Button size="sm" variant="secondary" type="button" onClick={onCancel}>
                    Annuler
                </Button>
            </div>
        </form>
    );
}

function ShipmentBlock({ s, order, busy, act, canShip, formats, defaultFormat }) {
    const [showHistory, setShowHistory] = useState(false);
    const [editing, setEditing] = useState(false);
    const [format, setFormat] = useState(defaultFormat);
    const muted = s.state === 'cancelled';
    return (
        <div className="space-y-2">
            <div className={`rounded-xl px-3 py-2 ring-1 ${muted ? 'bg-slate-50 ring-slate-200' : 'bg-violet-50 ring-violet-100'}`}>
                <div className="text-[11px] font-semibold uppercase tracking-wide text-violet-600">N° de suivi Sift</div>
                <div className="font-mono text-base font-bold text-slate-900">{s.tracking_number || '—'}</div>
                <div className="mt-1 text-sm font-semibold text-violet-700">
                    {s.raw_status_label || 'En attente'}
                    {s.raw_status ? <span className="ml-1.5 font-mono text-[11px] font-normal text-slate-500">({s.raw_status}{s.raw_sub_status ? ` · ${s.raw_sub_status}` : ''})</span> : null}
                </div>
                {s.raw_status_comment ? <div className="text-xs text-slate-600">{s.raw_status_comment}</div> : null}
                <div className="mt-0.5 text-[11px] text-slate-500">
                    {STATE_LABEL[s.state] || s.state}
                    {s.mapped_status ? ` · statut Lav’Fast : ${s.mapped_status_name || s.mapped_status}` : ''}
                    {s.status_at ? ` · ${formatDateTime(s.status_at)}` : ''}
                </div>
                <div className="text-[11px] text-slate-400">
                    Envoyée le {formatDateTime(s.created_at)}
                    {s.last_synced_at ? ` · dernière synchro ${formatDateTime(s.last_synced_at)}` : ''}
                </div>
                {s.reused_existing ? <div className="mt-1 text-[11px] font-semibold text-amber-700">Colis existant renvoyé par Sift (même n° de commande) : aucun doublon créé.</div> : null}
            </div>
            {s.last_error ? <div className="rounded-lg bg-rose-50 px-3 py-2 text-xs font-medium text-rose-700">{s.last_error}</div> : null}
            <div className="space-y-1">
                <Row label="parcelId">{s.parcel_id ? <span className="font-mono text-xs">{s.parcel_id}</span> : null}</Row>
                <Row label="customOrderNo">{s.custom_order_no ? <span className="font-mono text-xs">{s.custom_order_no}</span> : null}</Row>
                <Row label="Destinataire">{s.receiver}</Row>
                <Row label="Téléphone">{s.phone}</Row>
                <Row label="Ville">{s.city}</Row>
                <Row label="Adresse">{s.address}</Row>
                <Row label="COD">{s.cod_amount !== null ? formatDH(s.cod_amount) : null}</Row>
                <Row label="Ouverture">{s.allow_open === null ? null : s.allow_open ? 'Oui' : 'Non'}</Row>
            </div>
            {editing ? (
                <EditForm
                    s={s}
                    busy={busy}
                    onCancel={() => setEditing(false)}
                    onSubmit={(payload) => act('edit', () => api.put(`/sift/orders/${order.id}`, { ...payload, shipment_id: s.id })).then((ok) => ok && setEditing(false))}
                />
            ) : null}
            {s.parcel_id && !muted ? (
                <div className="flex flex-wrap items-center gap-2">
                    <Select value={format} onChange={(e) => setFormat(e.target.value)} className="h-8 w-44 py-0 text-xs" aria-label="Format de l’étiquette">
                        {Object.entries(formats).map(([k, v]) => (
                            <option key={k} value={k}>
                                {v}
                            </option>
                        ))}
                    </Select>
                    <a
                        href={`/api/sift/orders/${order.id}/waybill?format=${format}&shipment_id=${s.id}`}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="inline-flex h-8 items-center gap-1.5 rounded-xl bg-violet-600 px-2.5 text-xs font-semibold text-white hover:bg-violet-700"
                    >
                        <Download className="h-3.5 w-3.5" /> Télécharger l’étiquette Sift
                    </a>
                </div>
            ) : null}
            <div className="flex flex-wrap gap-2">
                <Button size="sm" variant="secondary" onClick={() => act('refresh', () => api.post(`/sift/orders/${order.id}/refresh`, { shipment_id: s.id }))} disabled={!!busy}>
                    <RefreshCw className={`h-3.5 w-3.5 ${busy === 'refresh' ? 'animate-spin' : ''}`} /> Actualiser depuis Sift
                </Button>
                {canShip && s.editable && !editing ? (
                    <Button size="sm" variant="secondary" onClick={() => setEditing(true)} disabled={!!busy}>
                        <Pencil className="h-3.5 w-3.5" /> Modifier
                    </Button>
                ) : null}
                {canShip && s.can_cancel ? (
                    <Button
                        size="sm"
                        variant="secondary"
                        className="text-rose-700"
                        onClick={() => {
                            const reason = window.prompt('Annuler ce colis chez Sift (statut « cancelled ») ? Motif (facultatif) :', '');
                            if (reason !== null) act('cancel', () => api.post(`/sift/orders/${order.id}/cancel`, { shipment_id: s.id, reason }));
                        }}
                        disabled={!!busy}
                    >
                        <Ban className="h-3.5 w-3.5" /> Annuler chez le transporteur
                    </Button>
                ) : null}
                {canShip && s.can_hide ? (
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => window.confirm('Supprimer / masquer ce colis ? Suppression logique côté Sift (DELETE) : n’annule rien chez le livreur.') && act('hide', () => api.post(`/sift/orders/${order.id}/hide`, { shipment_id: s.id }))}
                        disabled={!!busy}
                    >
                        <EyeOff className="h-3.5 w-3.5" /> Supprimer / masquer localement
                    </Button>
                ) : null}
            </div>
            {s.history?.length ? (
                <div>
                    <button type="button" onClick={() => setShowHistory((v) => !v)} className="inline-flex items-center gap-1 text-xs font-semibold text-slate-600 hover:text-slate-900">
                        <History className="h-3.5 w-3.5" /> {showHistory ? 'Masquer' : 'Voir'} l’historique Sift ({s.history.length})
                    </button>
                    {showHistory ? (
                        <ol className="mt-1.5 space-y-1 border-l-2 border-violet-100 pl-3">
                            {s.history.map((h, i) => (
                                <li key={i} className="text-xs">
                                    <span className="font-semibold text-slate-800">{h.status}</span>
                                    {h.sub_status ? <span className="text-slate-500"> · {h.sub_status}</span> : null}
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

/** Fiche commande → Sift.ma: send (validation popup), parcel, refresh, edit, cancel, hide, waybill, resync. */
export default function SiftOrderCard({ order, onChanged }) {
    const { carriers } = useCarriers();
    const { can } = useAuth();
    const [busy, setBusy] = useState('');
    const [msg, setMsg] = useState(null);
    const [error, setError] = useState(null);
    const [dialog, setDialog] = useState(false);
    const carrier = carriers.find((c) => c.key === 'sift');
    const shipments = (order.sift_shipments || []).filter((s) => !s.hidden_at);
    const current = order.sift;
    const others = shipments.filter((s) => s.id !== current?.id);
    const canShip = can('orders.ship');

    if (!carrier || (!carrier.available && !shipments.length)) return null;

    async function act(name, fn) {
        setBusy(name);
        setMsg(null);
        setError(null);
        let ok = false;
        try {
            const { data } = await fn();
            setMsg(data.message);
            ok = true;
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy('');
            const { data } = await api.get(`/orders/${order.id}`);
            onChanged(data.data);
        }
        return ok;
    }

    const otherCarrier = order.shipment && order.shipment.carrier !== 'sift' ? order.shipment : null;
    const formats = carrier.waybill_formats || { STANDARD_100x100: 'Standard 100×100' };
    const blockProps = { order, busy, act, canShip, formats, defaultFormat: carrier.default_waybill_format || 'STANDARD_100x100' };

    return (
        <Card title="Sift.ma" actions={<Truck className="h-4 w-4 text-violet-600" />}>
            <div className="space-y-3">
                <AmountDueStaleNotice order={order} />
                <Alert>{error}</Alert>
                <Alert type="success">{msg}</Alert>
                {current ? (
                    <ShipmentBlock s={current} {...blockProps} />
                ) : otherCarrier ? (
                    <p className="text-sm text-slate-500">
                        Déjà envoyée à {otherCarrier.carrier_label} (n° {otherCarrier.tracking}).
                    </p>
                ) : (
                    <div className="space-y-2">
                        <p className="text-sm text-slate-500">Pas encore envoyée à Sift.</p>
                        {canShip ? (
                            <div className="flex flex-wrap gap-2">
                                <Button size="sm" onClick={() => setDialog(true)} disabled={!carrier.available} title={carrier.reason || ''} style={{ backgroundColor: carrier.color }}>
                                    <Send className="h-3.5 w-3.5" /> Envoyer à Sift
                                </Button>
                                <Button size="sm" variant="secondary" onClick={() => act('resync', () => api.post(`/sift/orders/${order.id}/resync`))} disabled={!!busy || !carrier.available} title="Retrouver le colis chez Sift par n° de commande (customOrderNo)">
                                    <Search className="h-3.5 w-3.5" /> Resynchroniser
                                </Button>
                            </div>
                        ) : null}
                        {!carrier.available ? <p className="text-xs text-amber-700">{carrier.reason}</p> : null}
                    </div>
                )}
                {others.length ? (
                    <div className="space-y-3 border-t border-slate-100 pt-3">
                        <div className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Autres colis Sift</div>
                        {others.map((s) => (
                            <ShipmentBlock key={s.id} s={s} {...blockProps} />
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
