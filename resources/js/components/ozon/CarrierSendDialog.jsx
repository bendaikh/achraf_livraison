import { useCallback, useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { Link } from 'react-router-dom';
import { AlertTriangle, CheckCircle2, ExternalLink, Loader2, RefreshCw, Send, Truck, X, XCircle } from 'lucide-react';
import api, { errorMessage } from '../../lib/api';
import { formatDH } from '../../lib/format';
import { useAuth } from '../../contexts/AuthContext';
import { Button, Input, Spinner } from '../ui';
import OzonCityPicker from './OzonCityPicker';

function Flag({ on, yes, no }) {
    return <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${on ? 'bg-teal-50 text-teal-700' : 'bg-slate-100 text-slate-500'}`}>{on ? yes : no}</span>;
}

/**
 * Validation popup before sending to a carrier with preview (Ozon Express): client, city (Ozon
 * ID), COD, ouverture, fragile, échange → « Confirmer l’envoi ». Already-sent orders show
 * « Cette commande est déjà envoyée à Ozon » with open / refresh actions instead.
 * Modes: orderIds (Commandes) or savId (Retours & échanges, exchange parcel).
 */
export default function CarrierSendDialog({ carrier, orderIds = [], savId = null, onClose, onDone }) {
    const { can } = useAuth();
    const [rows, setRows] = useState(null);
    const [meta, setMeta] = useState({});
    const [options, setOptions] = useState(null);
    const [price, setPrice] = useState('0');
    const [busy, setBusy] = useState('');
    const [error, setError] = useState(null);
    const [result, setResult] = useState(null);
    const canMap = can('settings.manage');

    const load = useCallback(
        async (opts) => {
            setError(null);
            try {
                const { data } = savId
                    ? await api.get(`/sav/${savId}/ozon`, { params: { price: Number(price || 0), ...(opts ? { open: opts.open ? 1 : 0, fragile: opts.fragile ? 1 : 0 } : {}) } })
                    : await api.post(`/carriers/${carrier.key}/preview`, { order_ids: orderIds, options: opts || {} });
                setRows(data.rows);
                setMeta({ unavailable: data.unavailable, bulkBlocked: data.bulk_blocked });
                if (!opts && data.rows[0]) setOptions({ open: data.rows[0].open, fragile: data.rows[0].fragile });
            } catch (e) {
                setError(errorMessage(e));
                setRows([]);
            }
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [carrier.key, orderIds.join(','), savId],
    );

    useEffect(() => {
        load(null);
    }, [load]);

    useEffect(() => {
        const onKey = (e) => e.key === 'Escape' && onClose();
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    const setOpt = (k, v) => {
        const next = { ...options, [k]: v };
        setOptions(next);
        setRows((list) => list.map((r) => ({ ...r, [k]: v })));
    };

    async function mapCity(row, city) {
        setBusy(`city-${row.order_id}`);
        try {
            await api.put('/integrations/ozon/city-mappings', { city: row.city, ozon_city_id: city ? city.id : null });
            await load(options);
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy('');
        }
    }

    async function refresh(row) {
        setBusy(`refresh-${row.order_id}`);
        setError(null);
        try {
            const { data } = await api.post(`/ozon/orders/${row.order_id}/refresh`);
            setResult({ ok: true, message: data.message });
            await load(options);
            onDone?.();
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy('');
        }
    }

    async function confirm() {
        setBusy('send');
        setError(null);
        const ids = rows.filter((r) => r.can_send).map((r) => r.order_id);
        try {
            const { data } = savId
                ? await api.post(`/sav/${savId}/ozon`, { price: Number(price || 0), ...options })
                : await api.post(`/carriers/${carrier.key}/ship`, { order_ids: ids, options });
            setResult({ ok: true, message: data.message, results: data.results });
            onDone?.(data);
        } catch (e) {
            const d = e?.response?.data;
            setResult({ ok: false, message: d?.results?.length === 1 ? d.results[0].message : errorMessage(e), results: d?.results });
            await load(options);
        } finally {
            setBusy('');
        }
    }

    const sendable = (rows || []).filter((r) => r.can_send);
    const blocked = meta.unavailable || meta.bulkBlocked;
    const isMobile = typeof window !== 'undefined' && window.innerWidth < 640;

    const body = (
        <div className="fixed inset-0 z-[60] flex items-end justify-center sm:items-center" onClick={(e) => e.stopPropagation()}>
            <div className="absolute inset-0 bg-slate-900/40" onClick={onClose} />
            <div role="dialog" aria-modal="true" aria-label={`Envoyer à ${carrier.label}`} className={`relative flex max-h-[92vh] w-full flex-col bg-white shadow-2xl ${isMobile ? 'rounded-t-2xl' : 'max-w-2xl rounded-2xl'}`}>
                <div className="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
                    <div className="flex items-center gap-2">
                        <span className="flex h-8 w-8 items-center justify-center rounded-lg text-white" style={{ backgroundColor: carrier.color }}>
                            <Truck className="h-4 w-4" />
                        </span>
                        <div>
                            <div className="text-sm font-bold text-slate-900">{savId ? `Envoyer l’échange à ${carrier.label}` : `Envoyer à ${carrier.label}`}</div>
                            <div className="text-[11px] text-slate-500">Vérifiez les informations avant de confirmer l’envoi.</div>
                        </div>
                    </div>
                    <button type="button" onClick={onClose} className="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="Fermer">
                        <X className="h-5 w-5" />
                    </button>
                </div>

                <div className="flex-1 space-y-3 overflow-y-auto px-4 py-3">
                    {error ? (
                        <div className="flex items-start gap-1.5 rounded-lg bg-rose-50 px-3 py-2 text-sm font-medium text-rose-700">
                            <XCircle className="mt-0.5 h-4 w-4 shrink-0" /> {error}
                        </div>
                    ) : null}
                    {blocked ? <div className="rounded-lg bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800 ring-1 ring-amber-200">{blocked}</div> : null}
                    {result ? (
                        <div className={`rounded-lg px-3 py-2 text-sm font-medium ${result.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-rose-50 text-rose-700'}`}>
                            <div className="flex items-start gap-1.5">
                                {result.ok ? <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" /> : <XCircle className="mt-0.5 h-4 w-4 shrink-0" />}
                                {result.message}
                            </div>
                            {result.results?.length > 1 ? (
                                <ul className="mt-1.5 space-y-0.5 text-xs">
                                    {result.results.map((r) => (
                                        <li key={r.order_id}>
                                            {r.success ? '✓' : '✗'} {r.reference} — {r.message}
                                        </li>
                                    ))}
                                </ul>
                            ) : null}
                        </div>
                    ) : null}

                    {!rows ? (
                        <Spinner />
                    ) : (
                        <>
                            {options && !result?.ok ? (
                                <div className="flex flex-wrap items-center gap-4 rounded-xl bg-slate-50 px-3 py-2 text-sm">
                                    <label className="flex items-center gap-2 font-semibold text-slate-700">
                                        <input type="checkbox" className="h-4 w-4 rounded border-slate-300 text-teal-600" checked={options.open} onChange={(e) => setOpt('open', e.target.checked)} />
                                        Ouverture autorisée
                                    </label>
                                    <label className="flex items-center gap-2 font-semibold text-slate-700">
                                        <input type="checkbox" className="h-4 w-4 rounded border-slate-300 text-teal-600" checked={options.fragile} onChange={(e) => setOpt('fragile', e.target.checked)} />
                                        Fragile
                                    </label>
                                    {savId ? (
                                        <label className="flex items-center gap-2 font-semibold text-slate-700">
                                            Montant à encaisser
                                            <Input type="number" min="0" step="0.01" value={price} onChange={(e) => setPrice(e.target.value)} onBlur={() => load(options)} className="h-8 w-24" />
                                        </label>
                                    ) : null}
                                </div>
                            ) : null}
                            <ul className="space-y-2">
                                {rows.map((r) => (
                                    <li key={`${r.order_id}-${r.sav_request_id || ''}`} className={`rounded-xl border p-3 ${r.already ? 'border-amber-200 bg-amber-50/50' : r.errors.length ? 'border-rose-200 bg-rose-50/40' : 'border-slate-200'}`}>
                                        <div className="flex flex-wrap items-start justify-between gap-2">
                                            <div className="min-w-0">
                                                <div className="text-sm font-bold text-slate-900">
                                                    {r.reference} · {r.receiver || '—'}
                                                </div>
                                                <div className="text-xs text-slate-500">
                                                    {r.phone || 'Téléphone manquant'} · {r.address || 'Adresse manquante'}
                                                </div>
                                            </div>
                                            <div className="text-right">
                                                <div className="text-[11px] font-semibold uppercase text-slate-400">COD</div>
                                                <div className="text-sm font-bold text-slate-900">{formatDH(r.price)}</div>
                                            </div>
                                        </div>
                                        <div className="mt-2 grid gap-2 sm:grid-cols-2">
                                            <div>
                                                <div className="text-[11px] font-semibold uppercase text-slate-400">Ville</div>
                                                <div className="text-sm text-slate-700">
                                                    {r.city || '—'}
                                                    {r.ozon_city ? (
                                                        <span className="font-semibold text-teal-700">
                                                            {' '}
                                                            → {r.ozon_city.name} (#{r.ozon_city.id})
                                                        </span>
                                                    ) : null}
                                                </div>
                                                {!r.ozon_city && r.city && canMap && !r.already ? (
                                                    <div className="mt-1">
                                                        <OzonCityPicker value={null} suggestions={r.city_suggestions} disabled={busy === `city-${r.order_id}`} onChange={(c) => mapCity(r, c)} placeholder="Associer à une ville Ozon…" />
                                                    </div>
                                                ) : null}
                                            </div>
                                            <div className="flex flex-wrap items-end gap-1.5">
                                                <Flag on={r.open} yes="Ouverture : oui" no="Ouverture : non" />
                                                <Flag on={r.fragile} yes="Fragile" no="Non fragile" />
                                                <Flag on={r.replace} yes="Échange" no="Pas d’échange" />
                                                <Flag on={r.stock === 1} yes="Stock Ozon" no="Ramassage" />
                                            </div>
                                        </div>
                                        {r.nature ? <div className="mt-1.5 truncate text-xs text-slate-500">Nature : {r.nature}</div> : null}
                                        {r.products?.length ? <div className="text-xs text-slate-500">Produits : {r.products.map((p) => `${p.ref} ×${p.qnty}`).join(', ')}</div> : <div className="text-xs text-slate-400">Aucun produit avec SKU : liste produits non envoyée.</div>}
                                        {r.already ? (
                                            <div className="mt-2 rounded-lg bg-white px-3 py-2 ring-1 ring-amber-200">
                                                <div className="flex items-center gap-1.5 text-sm font-semibold text-amber-800">
                                                    <AlertTriangle className="h-4 w-4" /> Cette commande est déjà envoyée à Ozon
                                                </div>
                                                <div className="text-xs text-slate-600">
                                                    N° <span className="font-mono font-semibold">{r.already.tracking_number}</span> · {r.already.raw_status || 'Créé'}
                                                </div>
                                                <div className="mt-1.5 flex flex-wrap gap-2">
                                                    <Link to={`/commandes/${r.order_id}`} className="inline-flex items-center gap-1 text-xs font-semibold text-blue-600 hover:text-blue-700">
                                                        <ExternalLink className="h-3.5 w-3.5" /> Ouvrir l’envoi existant
                                                    </Link>
                                                    {!savId ? (
                                                        <button type="button" onClick={() => refresh(r)} disabled={!!busy} className="inline-flex items-center gap-1 text-xs font-semibold text-teal-700 hover:text-teal-800">
                                                            <RefreshCw className={`h-3.5 w-3.5 ${busy === `refresh-${r.order_id}` ? 'animate-spin' : ''}`} /> Actualiser depuis Ozon
                                                        </button>
                                                    ) : null}
                                                </div>
                                            </div>
                                        ) : null}
                                        {!r.already && r.errors.length ? (
                                            <ul className="mt-2 space-y-0.5 text-xs font-medium text-rose-700">
                                                {r.errors.map((e) => (
                                                    <li key={e}>• {e}</li>
                                                ))}
                                            </ul>
                                        ) : null}
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}
                </div>

                <div className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 px-4 py-3">
                    <span className="text-xs text-slate-500">{rows ? `${sendable.length} / ${rows.length} prête(s) à l’envoi` : ''}</span>
                    <div className="flex gap-2">
                        <Button variant="secondary" onClick={onClose}>
                            {result?.ok ? 'Fermer' : 'Annuler'}
                        </Button>
                        {!result?.ok ? (
                            <Button onClick={confirm} disabled={!!busy || !sendable.length || !!blocked} style={{ backgroundColor: carrier.color }}>
                                {busy === 'send' ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />} Confirmer l’envoi
                            </Button>
                        ) : null}
                    </div>
                </div>
            </div>
        </div>
    );

    return createPortal(body, document.body);
}
