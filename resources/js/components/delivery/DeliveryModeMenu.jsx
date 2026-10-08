import { useEffect, useMemo, useRef, useState } from 'react';
import { ChevronRight, MoreHorizontal, Search } from 'lucide-react';
import useDeliveryModes from '../../hooks/useDeliveryModes';
import { byLabel, fold, pushRecent, readRecents, recentCarrierKeys } from '../../lib/delivery';
import CarrierLogo from './CarrierLogo';
import DriverList from './DriverList';
import { Spinner } from '../ui';

/**
 * Searchable carrier list shared by the bulk bar, the per-row popup and the fiche commande.
 * Carrier-specific extras come from `documents` on each mode, never from a hardcoded key.
 */
export default function DeliveryModeMenu({ includeLocal = false, orderCount = 1, onSelect, onDocument, onClose, className = '' }) {
    const data = useDeliveryModes();
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);
    const [extra, setExtra] = useState(null);
    const [local, setLocal] = useState(false);
    const [stored, setStored] = useState(() => readRecents(data.user_id, data.company_id));
    const input = useRef(null);
    const panel = useRef(null);

    useEffect(() => {
        input.current?.focus();
    }, [local]);

    useEffect(() => {
        if (data.user_id) setStored(readRecents(data.user_id, data.company_id));
    }, [data.user_id, data.company_id]);

    const q = fold(query);
    const carriers = (data.modes || []).filter((m) => m.type === 'carrier');
    const localMode = includeLocal && data.can_assign_driver ? (data.modes || []).find((m) => m.type === 'local') : null;
    const connected = carriers.filter((m) => m.available);
    const offline = carriers.filter((m) => !m.available);
    const recentKeys = recentCarrierKeys(carriers, stored);
    const recent = recentKeys.map((key) => connected.find((m) => m.key === key)).filter(Boolean);
    const rest = connected.filter((m) => !recentKeys.includes(m.key)).sort(byLabel);

    const matches = (m) => !q || fold(`${m.label} ${m.key}`).includes(q);

    const items = useMemo(() => {
        const out = [];
        if (localMode && matches(localMode)) out.push({ kind: 'mode', mode: localMode });
        const rec = recent.filter(matches);
        if (rec.length) {
            out.push({ kind: 'header', label: 'Récemment utilisés' });
            rec.forEach((mode) => out.push({ kind: 'mode', mode }));
        }
        const others = rest.filter(matches);
        if (others.length) {
            if (rec.length) out.push({ kind: 'header', label: 'Transporteurs' });
            others.forEach((mode) => out.push({ kind: 'mode', mode }));
        }
        const grey = offline.filter(matches);
        if (grey.length) {
            out.push({ kind: 'header', label: 'Non connectés' });
            grey.forEach((mode) => out.push({ kind: 'mode', mode, offline: true }));
        }
        return out;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [query, data.modes, stored, includeLocal, orderCount]);

    const selectable = items.filter((it) => it.kind === 'mode' && !it.offline && !blocked(it.mode));

    function blocked(mode) {
        return mode.type === 'carrier' && orderCount > 1 && mode.bulk_enabled === false;
    }

    function hint(mode, isOffline) {
        if (blocked(mode)) return mode.bulk_reason || 'Actions groupées désactivées.';
        if (isOffline) return mode.reason || 'Non connecté';
        if (!data.can_ship && mode.type === 'carrier') return 'Vous n’avez pas la permission d’expédier.';
        return '';
    }

    function choose(mode) {
        if (mode.type === 'local') {
            setLocal(true);
            setQuery('');
            return;
        }
        if (!data.can_ship || blocked(mode) || !mode.available) return;
        pushRecent(data.user_id, data.company_id, mode.key);
        onSelect?.(mode);
    }

    function onKey(e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            if (local) setLocal(false);
            else onClose?.();
            return;
        }
        if (local) return;
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            if (!selectable.length) return;
            setActive((i) => {
                const next = e.key === 'ArrowDown' ? i + 1 : i - 1;
                return (next + selectable.length) % selectable.length;
            });
        }
        if (e.key === 'Enter' && selectable[active]) {
            e.preventDefault();
            choose(selectable[active].mode);
        }
    }

    if (data.loading) return <div className={`w-72 rounded-xl border border-slate-200 bg-white p-2 shadow-lg ${className}`}><Spinner label="Modes de livraison…" /></div>;

    return (
        <div ref={panel} onKeyDown={onKey} className={`w-72 rounded-xl border border-slate-200 bg-white p-2 shadow-lg ${className}`}>
            <div className="relative mb-1.5">
                <Search className="pointer-events-none absolute left-2 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" />
                <input
                    ref={input}
                    value={query}
                    onChange={(e) => {
                        setQuery(e.target.value);
                        setActive(0);
                    }}
                    placeholder={local ? 'Rechercher un livreur' : 'Rechercher un transporteur'}
                    aria-label={local ? 'Rechercher un livreur' : 'Rechercher un transporteur'}
                    className="h-8 w-full rounded-lg border border-slate-200 bg-slate-50 pl-7 pr-2 text-xs outline-none focus:border-blue-300 focus:ring-2 focus:ring-blue-500/10"
                />
            </div>
            {local ? (
                <>
                    <button type="button" onClick={() => setLocal(false)} className="mb-1 text-[11px] font-semibold text-slate-500 hover:text-slate-800">
                        ← Modes de livraison
                    </button>
                    <div className="px-1 pb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">Livreurs actifs</div>
                    <DriverList query={query} onPick={(d) => onSelect?.({ type: 'local', key: 'local', driver: d })} />
                </>
            ) : (
                <ul className="max-h-64 overflow-y-auto" role="listbox" aria-label="Modes de livraison">
                    {items.length === 0 ? <li className="px-2 py-3 text-xs text-slate-400">Aucun mode ne correspond.</li> : null}
                    {items.map((it, index) => {
                        if (it.kind === 'header') {
                            return (
                                <li key={`h-${it.label}-${index}`} className="px-2 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-wide text-slate-400">
                                    {it.label}
                                </li>
                            );
                        }
                        const mode = it.mode;
                        const isBlocked = blocked(mode) || it.offline || (mode.type === 'carrier' && !data.can_ship);
                        const selIndex = selectable.findIndex((s) => s.mode.key === mode.key && !it.offline);
                        const highlighted = !isBlocked && selIndex === active;
                        const title = hint(mode, it.offline);
                        return (
                            <li key={`${mode.type}-${mode.key}`}>
                                <div className={`flex items-center gap-1 rounded-lg ${highlighted ? 'bg-blue-50' : ''} ${isBlocked ? 'opacity-60' : 'hover:bg-slate-50'}`} title={title}>
                                    <button
                                        type="button"
                                        role="option"
                                        aria-selected={highlighted}
                                        aria-disabled={isBlocked}
                                        disabled={isBlocked}
                                        onMouseEnter={() => {
                                            if (!isBlocked && selIndex >= 0) setActive(selIndex);
                                        }}
                                        onClick={() => choose(mode)}
                                        className="flex min-w-0 flex-1 items-center gap-2 px-2 py-1.5 text-left text-sm disabled:cursor-not-allowed"
                                    >
                                        <CarrierLogo mode={mode} />
                                        <span className="min-w-0 flex-1 truncate font-semibold text-slate-800">{mode.label}</span>
                                        {!isBlocked ? <ChevronRight className="h-3.5 w-3.5 shrink-0 text-slate-300" /> : null}
                                    </button>
                                    {mode.documents?.length && !it.offline ? (
                                        <button
                                            type="button"
                                            aria-label={`Autres actions ${mode.label}`}
                                            title="Créer BL, étiquettes…"
                                            onClick={() => setExtra(extra === mode.key ? null : mode.key)}
                                            className="mr-1 rounded-md p-1 text-slate-400 hover:bg-white hover:text-slate-700"
                                        >
                                            <MoreHorizontal className="h-3.5 w-3.5" />
                                        </button>
                                    ) : null}
                                </div>
                                {extra === mode.key ? <DocumentRow mode={mode} onDocument={onDocument} /> : null}
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}

function DocumentRow({ mode, onDocument }) {
    const [format, setFormat] = useState(() => {
        const doc = mode.documents?.find((d) => d.formats);
        return doc ? Object.keys(doc.formats)[0] : '';
    });
    return (
        <div className="mb-1 ml-9 space-y-1">
            {mode.documents.map((doc) => (
                <div key={doc.key} className="flex items-center gap-1">
                    <button
                        type="button"
                        onClick={() => onDocument?.(mode, doc, doc.formats ? format : null)}
                        className="rounded-md px-2 py-1 text-[11px] font-semibold text-slate-600 hover:bg-slate-100"
                    >
                        {doc.label}
                    </button>
                    {doc.formats ? (
                        <select
                            aria-label={`Format étiquette ${mode.label}`}
                            value={format}
                            onChange={(e) => setFormat(e.target.value)}
                            className="h-6 rounded-md border border-slate-200 bg-white px-1 text-[10px] font-semibold text-slate-600"
                        >
                            {Object.entries(doc.formats).map(([k, v]) => (
                                <option key={k} value={k}>
                                    {v}
                                </option>
                            ))}
                        </select>
                    ) : null}
                </div>
            ))}
        </div>
    );
}
