import { useEffect, useMemo, useRef, useState } from 'react';
import { Search } from 'lucide-react';
import { fold } from '../../lib/delivery';

/** Compact searchable replacement for the wide status <select>. */
export default function StatusMenu({ statuses = [], onPick, onClose }) {
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);
    const input = useRef(null);
    const shown = useMemo(() => statuses.filter((s) => !query || fold(s.name).includes(fold(query))), [statuses, query]);

    useEffect(() => {
        input.current?.focus();
    }, []);

    function onKey(e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            onClose?.();
        } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            if (!shown.length) return;
            setActive((i) => (i + (e.key === 'ArrowDown' ? 1 : -1) + shown.length) % shown.length);
        } else if (e.key === 'Enter' && shown[active]) {
            e.preventDefault();
            onPick(shown[active]);
        }
    }

    return (
        <div className="w-64 rounded-xl border border-slate-200 bg-white p-2 shadow-lg" onKeyDown={onKey}>
            <div className="relative mb-1.5">
                <Search className="pointer-events-none absolute left-2 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" />
                <input
                    ref={input}
                    value={query}
                    onChange={(e) => {
                        setQuery(e.target.value);
                        setActive(0);
                    }}
                    placeholder="Rechercher un statut"
                    aria-label="Rechercher un statut"
                    className="h-8 w-full rounded-lg border border-slate-200 bg-slate-50 pl-7 pr-2 text-xs outline-none focus:border-blue-300"
                />
            </div>
            <ul className="max-h-64 overflow-y-auto" role="listbox" aria-label="Statuts">
                {shown.length === 0 ? <li className="px-2 py-2 text-xs text-slate-400">Aucun statut.</li> : null}
                {shown.map((st, i) => (
                    <li key={st.id}>
                        <button
                            type="button"
                            role="option"
                            aria-selected={i === active}
                            onMouseEnter={() => setActive(i)}
                            onClick={() => onPick(st)}
                            className={`flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm ${i === active ? 'bg-blue-50' : 'hover:bg-slate-50'}`}
                        >
                            <span className="h-2 w-2 shrink-0 rounded-full" style={{ backgroundColor: st.color || '#64748b' }} />
                            <span className="truncate font-semibold text-slate-800">{st.name}</span>
                        </button>
                    </li>
                ))}
            </ul>
        </div>
    );
}
