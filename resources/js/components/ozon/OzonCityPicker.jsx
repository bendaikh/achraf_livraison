import { useEffect, useRef, useState } from 'react';
import { ChevronDown, X } from 'lucide-react';
import api from '../../lib/api';

/** Searchable select of the official Ozon cities (GET /api/ozon/cities?q=). */
export default function OzonCityPicker({ value, suggestions = [], onChange, disabled = false, placeholder = 'Choisir une ville Ozon…' }) {
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const [list, setList] = useState([]);
    const box = useRef(null);

    useEffect(() => {
        if (!open) return undefined;
        const t = setTimeout(() => {
            api.get('/ozon/cities', { params: { q } }).then(({ data }) => setList(data.data)).catch(() => setList([]));
        }, 200);
        return () => clearTimeout(t);
    }, [q, open]);

    useEffect(() => {
        const onDoc = (e) => box.current && !box.current.contains(e.target) && setOpen(false);
        document.addEventListener('mousedown', onDoc);
        return () => document.removeEventListener('mousedown', onDoc);
    }, []);

    const choose = (c) => {
        setOpen(false);
        setQ('');
        onChange(c);
    };

    return (
        <div ref={box} className="relative">
            <div className="flex items-center gap-1">
                <button
                    type="button"
                    disabled={disabled}
                    onClick={() => setOpen((v) => !v)}
                    className={`flex h-9 w-full items-center justify-between gap-2 rounded-lg border px-2.5 text-left text-sm ${value ? 'border-slate-200 bg-white text-slate-800' : 'border-amber-300 bg-amber-50 text-amber-800'} disabled:opacity-60`}
                >
                    <span className="truncate">{value ? `${value.name} (#${value.id})` : placeholder}</span>
                    <ChevronDown className="h-4 w-4 shrink-0 text-slate-400" />
                </button>
                {value ? (
                    <button type="button" onClick={() => onChange(null)} disabled={disabled} className="rounded p-1 text-slate-400 hover:text-rose-600" aria-label="Retirer l’association">
                        <X className="h-4 w-4" />
                    </button>
                ) : null}
            </div>
            {!value && suggestions.length ? (
                <div className="mt-1 flex flex-wrap gap-1">
                    {suggestions.map((s) => (
                        <button key={s.id} type="button" disabled={disabled} onClick={() => choose(s)} className="rounded-md bg-teal-50 px-1.5 py-0.5 text-[11px] font-semibold text-teal-700 hover:bg-teal-100">
                            {s.name}
                        </button>
                    ))}
                </div>
            ) : null}
            {open ? (
                <div className="absolute z-30 mt-1 w-full min-w-60 rounded-xl border border-slate-200 bg-white p-1.5 shadow-lg">
                    <input autoFocus value={q} onChange={(e) => setQ(e.target.value)} placeholder="Rechercher (nom, réf)…" className="mb-1 h-8 w-full rounded-lg border border-slate-200 px-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/20" />
                    <ul className="max-h-56 overflow-y-auto">
                        {list.length === 0 ? <li className="px-2 py-1.5 text-xs text-slate-400">Aucune ville</li> : null}
                        {list.map((c) => (
                            <li key={c.id}>
                                <button type="button" onClick={() => choose(c)} className="flex w-full items-center justify-between rounded-lg px-2 py-1.5 text-left text-sm hover:bg-slate-50">
                                    <span className="truncate">{c.name}</span>
                                    <span className="ml-2 shrink-0 text-[11px] text-slate-400">
                                        #{c.id}
                                        {c.delivered_price !== null ? ` · ${c.delivered_price} DH` : ''}
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}
        </div>
    );
}
