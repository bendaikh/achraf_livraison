import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Link } from 'react-router-dom';
import { ArrowLeft, Bike, CheckCircle2, Copy, ExternalLink, PackagePlus, Printer, RefreshCw, Truck, X, XCircle } from 'lucide-react';
import api, { errorMessage } from '../../lib/api';
import { formatDateTime, formatDH } from '../../lib/format';
import useDeliveryModes from '../../hooks/useDeliveryModes';
import { Button, Spinner } from '../ui';
import CarrierSendDialog from '../ozon/CarrierSendDialog';
import DeliveryModeMenu from '../delivery/DeliveryModeMenu';
import SendConfirmDialog from '../delivery/SendConfirmDialog';
import CarrierLogo from '../delivery/CarrierLogo';

/** Delivery categories where the order is finished (no re-assignment from the popup). */
const FINAL_CATEGORIES = ['succes', 'retour', 'annulation'];

/**
 * T11 — per-row « Expédier » action of the Commandes list.
 * Not shipped yet → small popup: send to a delivery company (registry from /api/carriers)
 * or assign to a local driver. Already shipped → carrier, tracking and current status.
 */
export default function QuickShip({ order, onChanged, compact = false }) {
    const [open, setOpen] = useState(false);
    const btn = useRef(null);
    const s = order.shipment;
    const driver = order.driver;

    let trigger;
    if (s) {
        trigger = (
            <span className="inline-flex max-w-[115px] items-center gap-1 rounded-lg border px-1.5 py-1 text-xs font-semibold" style={{ borderColor: `${s.color}40`, color: s.color, backgroundColor: `${s.color}0d` }}>
                <Truck className="h-3.5 w-3.5 shrink-0" />
                <span className="truncate font-mono">{s.tracking}</span>
            </span>
        );
    } else if (driver) {
        trigger = (
            <span className="inline-flex items-center gap-1.5 rounded-lg border border-emerald-200 bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-700" title={`Livraison locale · ${driver.name}`}>
                <Bike className="h-3.5 w-3.5 shrink-0" />
                {compact ? <span className="max-w-[90px] truncate">{driver.name}</span> : 'Locale'}
            </span>
        );
    } else {
        trigger = (
            <span className="inline-flex items-center gap-1 rounded-lg border border-blue-200 bg-white px-2 py-1 text-xs font-semibold text-blue-700 hover:bg-blue-50">
                <PackagePlus className="h-3.5 w-3.5" />
                {compact ? null : 'Expédier'}
            </span>
        );
    }

    return (
        <>
            <button
                ref={btn}
                type="button"
                onClick={(e) => {
                    e.stopPropagation();
                    e.preventDefault();
                    setOpen((v) => !v);
                }}
                aria-label={s ? `Expédition ${s.carrier_label} ${s.tracking}` : driver ? `Livraison locale ${driver.name}` : `Expédier ${order.reference}`}
                aria-haspopup="dialog"
                aria-expanded={open}
                className="rounded-lg focus:outline-none focus-visible:ring-4 focus-visible:ring-blue-500/20"
            >
                {trigger}
            </button>
            {open ? <QuickShipPopup anchor={btn} order={order} onClose={() => setOpen(false)} onChanged={onChanged} /> : null}
        </>
    );
}

function QuickShipPopup({ anchor, order, onClose, onChanged }) {
    const panel = useRef(null);
    const [pos, setPos] = useState(null);
    const isMobile = typeof window !== 'undefined' && window.innerWidth < 640;
    const { can_assign_driver: canAssign } = useDeliveryModes();
    const shipped = order.shipment;
    const [step, setStep] = useState(shipped || order.driver ? 'info' : 'choose');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [done, setDone] = useState(null);
    const [dialog, setDialog] = useState(null);
    const [confirmCarrier, setConfirmCarrier] = useState(null);

    useLayoutEffect(() => {
        if (isMobile) return undefined;
        const place = () => {
            if (!anchor.current) return;
            const r = anchor.current.getBoundingClientRect();
            const width = 340;
            const left = Math.max(8, Math.min(window.innerWidth - width - 8, r.right - width));
            const below = window.innerHeight - r.bottom;
            setPos(below > 360 ? { top: r.bottom + 6, left, width } : { bottom: window.innerHeight - r.top + 6, left, width });
        };
        place();
        window.addEventListener('scroll', place, true);
        window.addEventListener('resize', place);
        return () => {
            window.removeEventListener('scroll', place, true);
            window.removeEventListener('resize', place);
        };
    }, [anchor, isMobile]);

    useEffect(() => {
        const onKey = (e) => e.key === 'Escape' && onClose();
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    async function assignDriver(driver) {
        setBusy(true);
        setError(null);
        try {
            const { data } = await api.post('/local-delivery/assign', { order_ids: [order.id], driver_id: driver.id });
            const r = data.results?.[0];
            if (r && !r.success) setError(r.message);
            else {
                setDone(data.message || 'Terminé.');
                setStep('done');
                onChanged?.();
            }
        } catch (e) {
            setError(e?.response?.data?.results?.[0]?.message || errorMessage(e));
        } finally {
            setBusy(false);
        }
    }

    function pickMode(mode) {
        setError(null);
        if (mode.driver) {
            assignDriver(mode.driver);
            return;
        }
        if (mode.preview) setDialog(mode);
        else if (mode.type === 'carrier') setConfirmCarrier(mode);
    }

    const notConfirmed = order.is_confirmed === false;
    const style = isMobile ? undefined : pos ? { position: 'fixed', ...pos } : { position: 'fixed', visibility: 'hidden' };

    const body = (
        <div className="fixed inset-0 z-50" onClick={(e) => e.stopPropagation()}>
            <div className={`absolute inset-0 ${isMobile ? 'bg-slate-900/40' : ''}`} onClick={onClose} />
            <div
                ref={panel}
                role="dialog"
                aria-label={`Expédition ${order.reference}`}
                style={style}
                className={`${isMobile ? 'absolute inset-x-0 bottom-0 max-h-[85vh] rounded-t-2xl' : 'rounded-2xl'} overflow-y-auto border border-slate-200 bg-white p-3 shadow-xl shadow-slate-900/10`}
            >
                <div className="mb-2 flex items-center justify-between gap-2">
                    <div className="flex min-w-0 items-center gap-1.5">
                        {step === 'choose' && (shipped || order.driver) ? (
                            <button type="button" onClick={() => { setStep('info'); setError(null); }} className="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="Retour">
                                <ArrowLeft className="h-4 w-4" />
                            </button>
                        ) : null}
                        <div className="min-w-0">
                            <div className="truncate text-sm font-bold text-slate-900">
                                {order.reference} · À encaisser : {formatDH(order.amount_due ?? order.amount)}
                            </div>
                            <div className="truncate text-[11px] text-slate-500">
                                {order.customer_name} · {order.city || '—'}
                            </div>
                        </div>
                    </div>
                    <button type="button" onClick={onClose} className="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="Fermer">
                        <X className="h-4 w-4" />
                    </button>
                </div>

                {error ? (
                    <div className="mb-2 flex items-start gap-1.5 rounded-lg bg-rose-50 px-2.5 py-2 text-xs font-medium text-rose-700">
                        <XCircle className="mt-0.5 h-3.5 w-3.5 shrink-0" /> {error}
                    </div>
                ) : null}

                {step === 'info' ? (
                    <ShipmentInfo order={order} onChanged={onChanged} canReassign={canAssign && !shipped && !FINAL_CATEGORIES.includes(order.delivery_status?.category)} onReassign={() => setStep('choose')} />
                ) : null}

                {step === 'choose' ? (
                    <div className="space-y-2">
                        {notConfirmed ? <p className="rounded-lg bg-amber-50 px-2.5 py-2 text-xs font-medium text-amber-700">Commande non confirmée : l’envoi sera refusé tant qu’elle n’est pas confirmée.</p> : null}
                        {busy ? <Spinner label="Affectation…" /> : <DeliveryModeMenu includeLocal orderCount={1} onSelect={pickMode} onClose={onClose} className="w-full shadow-none" />}
                    </div>
                ) : null}

                {step === 'done' ? (
                    <div className="flex items-start gap-2 rounded-lg bg-emerald-50 px-2.5 py-2 text-sm font-medium text-emerald-700">
                        <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" /> {done}
                    </div>
                ) : null}

                <div className="mt-3 border-t border-slate-100 pt-2 text-right">
                    <Link to={`/commandes/${order.id}`} className="text-xs font-semibold text-blue-600 hover:text-blue-700">
                        Ouvrir la commande →
                    </Link>
                </div>
            </div>
        </div>
    );

    if (dialog) {
        return (
            <CarrierSendDialog
                carrier={dialog}
                orderIds={[order.id]}
                onClose={() => { setDialog(null); onClose(); }}
                onDone={() => onChanged?.()}
            />
        );
    }

    if (confirmCarrier) {
        return (
            <SendConfirmDialog
                carrier={confirmCarrier}
                orderIds={[order.id]}
                onClose={() => setConfirmCarrier(null)}
                onDone={() => {
                    setConfirmCarrier(null);
                    setStep('done');
                    setDone('Envoi terminé.');
                    onChanged?.();
                }}
            />
        );
    }

    return createPortal(body, document.body);
}

function ShipmentInfo({ order, canReassign, onReassign, onChanged }) {
    const s = order.shipment;
    const [refreshing, setRefreshing] = useState(false);
    const [note, setNote] = useState(null);
    async function refreshOzon() {
        setRefreshing(true);
        setNote(null);
        try {
            const action = (order.delivery_mode?.actions || []).find((a) => a.key === 'refresh');
            const url = action?.url ? action.url.replace(/^\/api/, '') : `/${s.carrier}/orders/${order.id}/refresh`;
            const { data } = await api.request({ method: action?.method || 'POST', url });
            setNote({ ok: true, text: data.message });
            onChanged?.();
        } catch (e) {
            setNote({ ok: false, text: errorMessage(e) });
        } finally {
            setRefreshing(false);
        }
    }
    if (s) {
        return (
            <div className="space-y-2 text-sm">
                <div className="flex items-center gap-2">
                    <CarrierLogo mode={{ label: s.carrier_label, color: s.color, logo: order.delivery_mode?.logo }} />
                    <div>
                        <div className="font-semibold text-slate-800">{s.carrier_label}</div>
                        <div className="text-[11px] text-slate-500">Envoyée le {formatDateTime(s.shipped_at)}</div>
                    </div>
                </div>
                <div className="flex items-center justify-between rounded-lg bg-slate-50 px-2.5 py-1.5">
                    <span className="font-mono text-sm font-semibold text-slate-800">{s.tracking}</span>
                    <button type="button" onClick={() => navigator.clipboard?.writeText(s.tracking)} className="text-slate-400 hover:text-slate-700" aria-label="Copier le numéro de suivi">
                        <Copy className="h-3.5 w-3.5" />
                    </button>
                </div>
                <div>
                    <div className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Statut transporteur</div>
                    <div className="font-medium" style={{ color: s.color }}>
                        {s.status_label}
                    </div>
                    {s.status_message ? <div className="text-xs text-slate-500">{s.status_message}</div> : null}
                    {s.status_at ? <div className="text-[11px] text-slate-400">{formatDateTime(s.status_at)}</div> : null}
                    {s.last_error ? <div className="mt-1 text-xs text-rose-600">{s.last_error}</div> : null}
                </div>
                {s.delivery_note_ref ? <div className="text-xs text-slate-500">BL : <span className="font-mono font-semibold">{s.delivery_note_ref}</span></div> : null}
                {(order.delivery_mode?.actions || []).some((a) => a.key === 'refresh') || s.can_refresh ? (
                    <div>
                        <button type="button" onClick={refreshOzon} disabled={refreshing} className="inline-flex items-center gap-1 text-xs font-semibold text-teal-700 hover:text-teal-800 disabled:opacity-60">
                            <RefreshCw className={`h-3.5 w-3.5 ${refreshing ? 'animate-spin' : ''}`} /> Actualiser statut
                        </button>
                        {note ? <div className={`mt-1 text-xs ${note.ok ? 'text-emerald-700' : 'text-rose-600'}`}>{note.text}</div> : null}
                    </div>
                ) : null}
                {s.label_url ? (
                    <a href={s.label_url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-xs font-semibold text-blue-600 hover:text-blue-700">
                        <Printer className="h-3.5 w-3.5" /> Étiquette <ExternalLink className="h-3 w-3" />
                    </a>
                ) : null}
            </div>
        );
    }
    return (
        <div className="space-y-2 text-sm">
            <div className="flex items-center gap-2">
                <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-emerald-600 text-white">
                    <Bike className="h-4 w-4" />
                </span>
                <div>
                    <div className="font-semibold text-slate-800">Livraison locale · {order.driver?.name}</div>
                    <div className="text-[11px] text-slate-500">
                        {order.assigned_at ? `Affectée le ${formatDateTime(order.assigned_at)}` : 'Livreur affecté'}
                        {order.assigned_by_name ? ` par ${order.assigned_by_name}` : ''}
                    </div>
                </div>
            </div>
            <div className="text-xs text-slate-500">Statut : {order.delivery_status?.name || order.delivery_status_label || '—'}</div>
            {canReassign ? (
                <Button size="sm" variant="secondary" onClick={onReassign}>
                    <Bike className="h-3.5 w-3.5" /> Réaffecter
                </Button>
            ) : null}
        </div>
    );
}
