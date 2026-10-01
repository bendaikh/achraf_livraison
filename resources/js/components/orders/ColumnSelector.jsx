import { useEffect, useRef, useState } from 'react';
import { Columns3, Monitor, Smartphone } from 'lucide-react';
import { DEFAULT_COLUMN_PREFS, MOBILE_MAX, ORDER_COLUMNS } from './orderColumns';

export default function ColumnSelector({ prefs, onChange }) {
    const [open, setOpen] = useState(false);
    const [mode, setMode] = useState(() => (typeof window !== 'undefined' && window.innerWidth < 768 ? 'mobile' : 'desktop'));
    const ref = useRef(null);

    useEffect(() => {
        if (!open) return undefined;
        const onDoc = (e) => ref.current && !ref.current.contains(e.target) && setOpen(false);
        document.addEventListener('mousedown', onDoc);
        return () => document.removeEventListener('mousedown', onDoc);
    }, [open]);

    const current = prefs[mode] || [];
    const columns = mode === 'mobile' ? ORDER_COLUMNS.filter((c) => c.key !== 'reference') : ORDER_COLUMNS;

    function toggle(key) {
        const has = current.includes(key);
        if (has && mode === 'desktop' && current.length <= 1) return;
        if (!has && mode === 'mobile' && current.length >= MOBILE_MAX) return;
        const next = has ? current.filter((k) => k !== key) : ORDER_COLUMNS.map((c) => c.key).filter((k) => k === key || current.includes(k));
        onChange({ ...prefs, [mode]: next });
    }

    return (
        <div className="relative" ref={ref}>
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                className="inline-flex h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                aria-expanded={open}
                title="Sélecteur de colonnes"
            >
                <Columns3 className="h-4 w-4" />
                <span className="hidden sm:inline">Colonnes</span>
            </button>
            {open ? (
                <div className="absolute right-0 z-40 mt-2 w-72 rounded-2xl border border-slate-200 bg-white p-3 shadow-xl">
                    <div className="mb-2 grid grid-cols-2 gap-1 rounded-xl bg-slate-100 p-1 text-xs font-semibold">
                        {[
                            ['desktop', 'Ordinateur', Monitor],
                            ['mobile', 'Mobile', Smartphone],
                        ].map(([m, label, Icon]) => (
                            <button
                                key={m}
                                type="button"
                                onClick={() => setMode(m)}
                                className={`inline-flex items-center justify-center gap-1 rounded-lg py-1.5 ${mode === m ? 'bg-white text-blue-700 shadow-sm' : 'text-slate-500'}`}
                            >
                                <Icon className="h-3.5 w-3.5" /> {label}
                            </button>
                        ))}
                    </div>
                    <p className="mb-2 text-[11px] text-slate-400">
                        {mode === 'mobile'
                            ? `Informations affichées sur les cartes mobiles (max. ${MOBILE_MAX}). Le n° de commande est toujours visible.`
                            : 'Colonnes affichées dans le tableau. Votre choix est mémorisé.'}
                    </p>
                    <ul className="max-h-72 space-y-0.5 overflow-y-auto">
                        {columns.map((c) => {
                            const checked = current.includes(c.key);
                            const disabled = (!checked && mode === 'mobile' && current.length >= MOBILE_MAX) || (checked && mode === 'desktop' && current.length <= 1);
                            return (
                                <li key={c.key}>
                                    <label className={`flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm ${disabled ? 'opacity-50' : 'cursor-pointer hover:bg-slate-50'}`}>
                                        <input type="checkbox" checked={checked} disabled={disabled} onChange={() => toggle(c.key)} className="h-4 w-4 accent-blue-600" />
                                        <span className="font-medium text-slate-700">{c.label}</span>
                                    </label>
                                </li>
                            );
                        })}
                    </ul>
                    <button
                        type="button"
                        onClick={() => onChange({ ...prefs, [mode]: DEFAULT_COLUMN_PREFS[mode] })}
                        className="mt-2 w-full rounded-lg py-1.5 text-xs font-semibold text-blue-600 hover:bg-blue-50"
                    >
                        Rétablir par défaut
                    </button>
                </div>
            ) : null}
        </div>
    );
}
