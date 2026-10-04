import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Link } from 'react-router-dom';
import { ArrowLeft, Bike, CheckCircle2, ChevronRight, Copy, ExternalLink, PackagePlus, Printer, RefreshCw, Truck, X, XCircle } from 'lucide-react';
import api, { errorMessage } from '../../lib/api';
import { formatDateTime, formatDH } from '../../lib/format';
import useCarriers from '../../hooks/useCarriers';
import { Button, Spinner } from '../ui';
import CarrierSendDialog from '../ozon/CarrierSendDialog';

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
    const { carriers, can_ship: canShip, can_assign_driver: canAssign, loading } = useCarriers();
    const shipped = order.shipment;
    const [step, setStep] = useState(shipped || order.driver ? 'info' : 'choose'); // info | choose | carrier | local | done
    const [carrier, setCarrier] = useState(null);
    const [drivers, setDrivers] = useState(null);
    const [driverId, setDriverId] = useState(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [done, setDone] = useState(null);
    const [dialog, setDialog] = useState(null); // carrier with a validation preview (Ozon)

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

    useEffect(() => {
        if (step !== 'local' || drivers) return;
        api.get('/local-delivery/drivers')
            .then(({ data }) => setDrivers(data.drivers))
            .catch((e) => setError(errorMessage(e)));
    }, [step, drivers]);

    async function confirm() {
        setBusy(true);
        setError(null);
        try {
            const { data } =
                step === 'carrier'
                    ? await api.post(`/carriers/${carrier.key}/ship`, { order_ids: [order.id] })
                    : await api.post('/local-delivery/assign', { order_ids: [order.id], driver_id: driverId });
            const r = data.results?.[0];
            if (r && !r.success) {
                setError(r.message);
            } else {
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
                        {['carrier', 'local'].includes(step) ? (
                            <button type="button" onClick={() => { setStep('choose'); setError(null); }} className="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="Retour">
                                <ArrowLeft className="h-4 w-4" />
                            </button>
                        ) : null}
                        <div className="min-w-0">
                            <div className="truncate text-sm font-bold text-slate-900">
                                {order.reference} · {formatDH(order.amount)}
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
                    <ShipmentInfo order={order} onChanged={onChanged} canReassign={canAssign && !shipped && !FINAL_CATEGORIES.includes(order.delivery_status?.category)} onReassign={() => setStep('local')} />
                ) : null}

                {step === 'choose' ? (
                    loading ? (
                        <Spinner />
                    ) : (
                        <div className="space-y-3">
                            {notConfirmed ? <p className="rounded-lg bg-amber-50 px-2.5 py-2 text-xs font-medium text-amber-700">Commande non confirmée : l’envoi sera refusé tant qu’elle n’est pas confirmée.</p> : null}
                            <div>
                                <div className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">Envoyer à une société de livraison</div>
                                <div className="space-y-1">
                                    {carriers.length === 0 ? <p className="text-xs text-slate-400">Aucune société de livraison configurée.</p> : null}
                                    {carriers.map((c) => {
                                        const disabled = !c.available || !canShip;
                                        return (
                                            <button
                                                key={c.key}
                                                type="button"
                                                disabled={disabled}
                                                onClick={() => { setError(null); if (c.preview) { setDialog(c); } else { setCarrier(c); setStep('carrier'); } }}
                                                title={!canShip ? 'Vous n’avez pas la permission d’expédier.' : c.reason || ''}
                                                className="flex w-full items-center gap-2 rounded-xl border border-slate-200 px-2.5 py-2 text-left text-sm hover:border-blue-200 hover:bg-blue-50/50 disabled:cursor-not-allowed disabled:opacity-55 disabled:hover:bg-white"
                                            >
                                                <span className="flex h-7 w-7 items-center justify-center rounded-lg text-[11px] font-extrabold text-white" style={{ backgroundColor: c.color }}>
                                                    {c.label.slice(0, 2).toUpperCase()}
                                                </span>
                                                <span className="min-w-0 flex-1">
                                                    <span className="block font-semibold text-slate-800">{c.label}</span>
                                                    {!c.available ? <span className="block truncate text-[11px] text-slate-400">{c.reason}</span> : null}
                                                </span>
                                                <ChevronRight className="h-4 w-4 text-slate-300" />
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>
                            <div>
                                <div className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">Livraison locale</div>
                                <button
                                    type="button"
                                    disabled={!canAssign}
                                    onClick={() => { setStep('local'); setError(null); }}
                                    title={canAssign ? '' : 'Vous n’avez pas la permission d’affecter un livreur.'}
                                    className="flex w-full items-center gap-2 rounded-xl border border-slate-200 px-2.5 py-2 text-left text-sm hover:border-emerald-200 hover:bg-emerald-50/50 disabled:cursor-not-allowed disabled:opacity-55"
                                >
                                    <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-emerald-600 text-white">
                                        <Bike className="h-4 w-4" />
                                    </span>
                                    <span className="flex-1 font-semibold text-slate-800">Affecter à livraison locale</span>
                                    <ChevronRight className="h-4 w-4 text-slate-300" />
                                </button>
                            </div>
                        </div>
                    )
                ) : null}

                {step === 'carrier' && carrier ? (
                    <div className="space-y-3">
                        <p className="text-sm text-slate-600">
                            Créer le colis chez <strong>{carrier.label}</strong> ? Le numéro de suivi et le statut seront ajoutés à la commande et à son historique.
                        </p>
                        <Button className="w-full" onClick={confirm} disabled={busy}>
                            <Truck className="h-4 w-4" /> {busy ? 'Envoi…' : `Confirmer l’envoi à ${carrier.label}`}
                        </Button>
                    </div>
                ) : null}

                {step === 'local' ? (
                    <div className="space-y-2">
                        {!drivers ? (
                            <Spinner />
                        ) : drivers.length === 0 ? (
                            <p className="text-xs text-slate-500">Aucun livreur actif. Ajoutez-en dans Livreurs.</p>
                        ) : (
                            <div className="max-h-60 space-y-1 overflow-y-auto" role="radiogroup" aria-label="Livreurs">
                                {drivers.map((d) => (
                                    <button
                                        key={d.id}
                                        type="button"
                                        role="radio"
                                        aria-checked={driverId === d.id}
                                        disabled={order.driver?.id === d.id}
                                        onClick={() => setDriverId(d.id)}
                                        className={`flex w-full items-center gap-2 rounded-xl border px-2.5 py-1.5 text-left text-sm disabled:opacity-50 ${driverId === d.id ? 'border-emerald-300 bg-emerald-50 ring-2 ring-emerald-100' : 'border-slate-200 hover:bg-slate-50'}`}
                                    >
                                        <Bike className="h-4 w-4 shrink-0 text-emerald-600" />
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate font-semibold text-slate-800">
                                                {d.name}
                                                {order.driver?.id === d.id ? ' (actuel)' : ''}
                                            </span>
                                            <span className="block truncate text-[11px] text-slate-500">
                                                {d.missions_in_progress} en cours{d.city ? ` · ${d.city}` : ''}
                                            </span>
                                        </span>
                                    </button>
                                ))}
                            </div>
                        )}
                        <Button className="w-full" onClick={confirm} disabled={busy || !driverId}>
                            <Bike className="h-4 w-4" /> {busy ? 'Affectation…' : order.driver ? 'Réaffecter' : 'Affecter'}
                        </Button>
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
            const { data } = await api.post(`/ozon/orders/${order.id}/refresh`);
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
                    <span className="flex h-7 w-7 items-center justify-center rounded-lg text-[11px] font-extrabold text-white" style={{ backgroundColor: s.color }}>
                        {s.carrier_label.slice(0, 2).toUpperCase()}
                    </span>
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
                {s.delivery_note_ref ? <div className="text-xs text-slate-500">BL Ozon : <span className="font-mono font-semibold">{s.delivery_note_ref}</span></div> : null}
                {s.carrier === 'ozon' ? (
                    <div>
                        <button type="button" onClick={refreshOzon} disabled={refreshing} className="inline-flex items-center gap-1 text-xs font-semibold text-teal-700 hover:text-teal-800 disabled:opacity-60">
                            <RefreshCw className={`h-3.5 w-3.5 ${refreshing ? 'animate-spin' : ''}`} /> Actualiser depuis Ozon
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
