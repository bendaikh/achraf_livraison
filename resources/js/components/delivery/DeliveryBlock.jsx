import { useState } from 'react';
import { Link } from 'react-router-dom';
import { MoreHorizontal } from 'lucide-react';
import api, { errorMessage } from '../../lib/api';
import { formatDH, formatDate, formatDateTime } from '../../lib/format';
import { useAuth } from '../../contexts/AuthContext';
import { useMeta } from '../../context/MetaContext';
import useDeliveryModes from '../../hooks/useDeliveryModes';
import { Button, Card } from '../ui';
import AmountDueStaleNotice from '../orders/AmountDueStaleNotice';
import CarrierSendDialog from '../ozon/CarrierSendDialog';
import CarrierLogo from './CarrierLogo';
import DeliveryModeMenu from './DeliveryModeMenu';
import DriverList from './DriverList';
import SendConfirmDialog from './SendConfirmDialog';
import { detailFor } from './carrierDetails';
import { runAction } from './runAction';

const PRIMARY = new Set(['track', 'label', 'delivery_note', 'refresh']);

/**
 * Single « Expédition / Livraison » card. The mode list is the same registry as the bulk bar.
 */
export default function DeliveryBlock({ order, onChanged }) {
    const { can } = useAuth();
    const meta = useMeta();
    const access = useDeliveryModes();
    const mode = order.delivery_mode;
    const [open, setOpen] = useState(false);
    const [drivers, setDrivers] = useState(false);
    const [carrier, setCarrier] = useState(null);
    const [preview, setPreview] = useState(null);
    const [menu, setMenu] = useState(false);
    const [notice, setNotice] = useState(null);
    const [busy, setBusy] = useState('');
    const [uiRequest, setUiRequest] = useState(null);
    const [changeNote, setChangeNote] = useState(null);

    const shipped = mode?.type === 'carrier';
    const local = mode?.type === 'local';
    const Detail = shipped ? detailFor(mode.key) : null;
    const actions = mode?.actions || [];
    const primary = actions.filter((a) => PRIMARY.has(a.key));
    const extras = actions.filter((a) => !PRIMARY.has(a.key));
    const due = Number(order.amount_due ?? 0);

    function pick(picked) {
        setOpen(false);
        if (picked?.driver) {
            assign(picked.driver);
            return;
        }
        if (picked?.type === 'local') return;
        if (picked?.preview) setPreview(picked);
        else if (picked) setCarrier(picked);
    }

    async function assign(driver) {
        setDrivers(false);
        setBusy('driver');
        setNotice(null);
        try {
            const { data } = await api.post('/local-delivery/assign', { order_ids: [order.id], driver_id: driver.id });
            setNotice(data.message);
            onChanged?.();
        } catch (e) {
            setNotice(e?.response?.data?.message || errorMessage(e));
        } finally {
            setBusy('');
        }
    }

    async function removeDriver() {
        if (!window.confirm('Retirer le livreur de cette commande ?')) return;
        setBusy('remove');
        try {
            const { data } = await api.put(`/orders/${order.id}`, { driver_id: null });
            onChanged?.(data.data || data);
        } catch (e) {
            setNotice(errorMessage(e));
        } finally {
            setBusy('');
        }
    }

    async function act(action) {
        if (action.ui) {
            setUiRequest(action);
            setMenu(false);
            return;
        }
        setBusy(action.key);
        setNotice(null);
        try {
            const data = await runAction(action, { orderId: order.id });
            if (data?.data) onChanged?.(data.data);
            else if (data && !data.opened) onChanged?.();
            if (data?.message) setNotice(data.message);
            setMenu(false);
        } catch (e) {
            setNotice(errorMessage(e));
        } finally {
            setBusy('');
        }
    }

    async function changeMode() {
        setMenu(false);
        const cancel = actions.find((a) => a.key === 'cancel');
        if (!cancel) {
            setChangeNote('Le colis actuel doit être annulé chez le transporteur avant de changer de mode. Ce transporteur ne propose pas d’annulation depuis Lav’Fast Flow.');
            return;
        }
        setChangeNote('Le colis actuel doit d’abord être annulé chez le transporteur. Annulez-le, puis choisissez un autre mode.');
        setBusy('cancel');
        try {
            const data = await runAction(cancel, { orderId: order.id });
            if (!data) return;
            if (data.data) onChanged?.(data.data);
            else onChanged?.();
            setOpen(true);
        } catch (e) {
            setNotice(errorMessage(e));
        } finally {
            setBusy('');
        }
    }

    const amount = local && due === 0 ? 'Rien à encaisser' : `À encaisser : ${formatDH(due)}`;
    const mission = mode?.mission || order.missions?.[0];
    const driver = mode?.driver || order.driver;

    return (
        <Card title="Expédition / Livraison">
            <div className="space-y-3">
                <AmountDueStaleNotice order={order} />
                {notice ? <div className="rounded-lg bg-slate-50 px-3 py-2 text-xs font-medium text-slate-700">{notice}</div> : null}
                {changeNote ? <div className="rounded-lg bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800">{changeNote}</div> : null}

                {!shipped && !local ? (
                    <div className="flex flex-wrap items-center gap-3">
                        <div className="relative">
                            <Button onClick={() => setOpen((v) => !v)} disabled={!!busy} aria-expanded={open}>
                                Expédier
                            </Button>
                            {open ? (
                                <div className="absolute left-0 top-full z-30 mt-1">
                                    <DeliveryModeMenu includeLocal orderCount={1} onSelect={pick} onClose={() => setOpen(false)} />
                                </div>
                            ) : null}
                        </div>
                        <div>
                            <div className="text-xs text-slate-500">Choisir le mode de livraison</div>
                            <div className="text-sm font-semibold text-slate-800">{amount}</div>
                        </div>
                    </div>
                ) : null}

                {shipped ? (
                    <div className="space-y-2">
                        <div className="flex items-center gap-2">
                            <CarrierLogo mode={mode} className="h-9 w-9" />
                            <div className="min-w-0">
                                <div className="font-semibold text-slate-900">{mode.label}</div>
                                <div className="text-sm text-slate-600">
                                    Tracking : <span className="font-mono font-semibold text-slate-900">{mode.tracking || '—'}</span>
                                </div>
                            </div>
                        </div>
                        <div className="text-sm text-slate-700">Statut : {mode.status_label || '—'}</div>
                        <div className="text-sm font-semibold text-slate-800">{amount}</div>
                        {!Detail && order.shipment?.status_message ? <div className="text-xs text-slate-500">{order.shipment.status_message}</div> : null}
                        {!Detail && order.shipment?.last_error ? <div className="text-xs font-medium text-rose-700">{order.shipment.last_error}</div> : null}
                        {Detail ? <Detail order={order} onChanged={onChanged} request={uiRequest} onRequestHandled={() => setUiRequest(null)} /> : null}
                        <div className="flex flex-wrap items-center gap-1.5">
                            {mode.tracking_url ? (
                                <a href={mode.tracking_url} target="_blank" rel="noopener noreferrer" className="inline-flex h-8 items-center rounded-xl border border-slate-200 px-2.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                    Suivre
                                </a>
                            ) : null}
                            {primary.filter((a) => a.key !== 'track' || !mode.tracking_url).map((a) => (
                                <Button key={a.key} size="sm" variant="secondary" disabled={!!busy} onClick={() => act(a)}>
                                    {busy === a.key ? '…' : a.label}
                                </Button>
                            ))}
                            <div className="relative">
                                <Button size="sm" variant="ghost" aria-label="Autres actions" onClick={() => setMenu((v) => !v)}>
                                    <MoreHorizontal className="h-4 w-4" />
                                </Button>
                                {menu ? (
                                    <div className="absolute right-0 z-20 mt-1 w-56 rounded-xl border border-slate-200 bg-white p-1 shadow-lg">
                                        {extras.map((a) => (
                                            <button key={a.key} type="button" onClick={() => act(a)} className="block w-full rounded-lg px-2 py-1.5 text-left text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                                {a.label}
                                            </button>
                                        ))}
                                        {can('orders.ship') ? (
                                            <button type="button" onClick={changeMode} className="block w-full rounded-lg px-2 py-1.5 text-left text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                                Changer de mode de livraison
                                            </button>
                                        ) : null}
                                    </div>
                                ) : null}
                            </div>
                        </div>
                        {mode.history?.length ? (
                            <details className="text-xs text-slate-500">
                                <summary className="cursor-pointer font-semibold text-slate-600">Historique des envois</summary>
                                <ul className="mt-1 space-y-1">
                                    {mode.history.map((h, i) => (
                                        <li key={`${h.tracking}-${i}`}>
                                            {h.carrier_label} · <span className="font-mono">{h.tracking || '—'}</span> · {h.status_label}
                                            {h.shipped_at ? ` · ${formatDateTime(h.shipped_at)}` : ''}
                                        </li>
                                    ))}
                                </ul>
                            </details>
                        ) : null}
                    </div>
                ) : null}

                {local ? (
                    <div className="space-y-2">
                        <div className="font-semibold text-slate-900">Livraison locale</div>
                        <div className="text-sm text-slate-700">
                            {driver?.name || 'Livreur'}
                            {driver?.phone ? ` · ${driver.phone}` : ''}
                        </div>
                        <div className="text-sm text-slate-600">Statut : {mode.status_label || order.delivery_status?.name || '—'}</div>
                        {mission ? (
                            <div className="text-xs text-slate-500">
                                Mission{' '}
                                <Link to="/missions" className="font-semibold text-blue-600 hover:text-blue-700">
                                    {mission.reference}
                                </Link>
                                {mission.scheduled_date ? ` · ${formatDate(mission.scheduled_date)}` : ''}
                                {mission.status ? ` · ${meta.missionStatusMap?.[mission.status]?.label || mission.status}` : ''}
                                {can('drivers.manage') && mission.driver_price != null ? ` · Tarif livreur : ${formatDH(mission.driver_price)}` : ''}
                            </div>
                        ) : null}
                        <div className="text-sm font-semibold text-slate-800">{amount}</div>
                        {can('orders.assign_driver') ? (
                            <div className="flex flex-wrap gap-2">
                                <Button size="sm" variant="secondary" disabled={!!busy} onClick={() => setDrivers((v) => !v)}>
                                    Changer de livreur
                                </Button>
                                <Button size="sm" variant="secondary" disabled={!!busy} onClick={removeDriver}>
                                    Retirer le livreur
                                </Button>
                            </div>
                        ) : null}
                        {drivers ? (
                            <div className="rounded-xl border border-slate-200 p-2">
                                <DriverList currentId={driver?.id} onPick={assign} />
                            </div>
                        ) : null}
                    </div>
                ) : null}
            </div>
            {carrier ? (
                <SendConfirmDialog
                    carrier={carrier}
                    orderIds={[order.id]}
                    onClose={() => setCarrier(null)}
                    onDone={() => {
                        setCarrier(null);
                        onChanged?.();
                    }}
                />
            ) : null}
            {preview ? (
                <CarrierSendDialog
                    carrier={preview}
                    orderIds={[order.id]}
                    onClose={() => setPreview(null)}
                    onDone={() => {
                        setPreview(null);
                        onChanged?.();
                    }}
                />
            ) : null}
        </Card>
    );
}
