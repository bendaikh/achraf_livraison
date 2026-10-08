import { useEffect, useMemo, useState } from 'react';
import { Bike } from 'lucide-react';
import api, { errorMessage } from '../../lib/api';
import { fold } from '../../lib/delivery';
import { Spinner } from '../ui';

/** Active livreurs only (GET /api/local-delivery/drivers), searchable. */
export default function DriverList({ onPick, currentId = null, query = '' }) {
    const [drivers, setDrivers] = useState(null);
    const [error, setError] = useState(null);

    useEffect(() => {
        let alive = true;
        api.get('/local-delivery/drivers')
            .then(({ data }) => {
                if (alive) setDrivers(data.drivers || []);
            })
            .catch((e) => {
                if (alive) setError(errorMessage(e));
            });
        return () => {
            alive = false;
        };
    }, []);

    const shown = useMemo(() => {
        const q = fold(query);
        return (drivers || []).filter((d) => !q || fold(`${d.name} ${d.phone || ''} ${d.city || ''}`).includes(q));
    }, [drivers, query]);

    if (error) return <p className="px-2 py-2 text-xs font-medium text-rose-700">{error}</p>;
    if (!drivers) return <Spinner label="Livreurs…" />;
    if (shown.length === 0) return <p className="px-2 py-2 text-xs text-slate-500">{drivers.length === 0 ? 'Aucun livreur actif.' : 'Aucun livreur ne correspond.'}</p>;

    return (
        <ul className="max-h-52 space-y-0.5 overflow-y-auto" role="listbox" aria-label="Livreurs actifs">
            {shown.map((d) => (
                <li key={d.id}>
                    <button
                        type="button"
                        role="option"
                        disabled={currentId != null && Number(currentId) === Number(d.id)}
                        onClick={() => onPick(d)}
                        className="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm hover:bg-emerald-50 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <Bike className="h-3.5 w-3.5 shrink-0 text-emerald-600" />
                        <span className="min-w-0 flex-1">
                            <span className="block truncate font-semibold text-slate-800">
                                {d.name}
                                {currentId != null && Number(currentId) === Number(d.id) ? ' (actuel)' : ''}
                            </span>
                            <span className="block truncate text-[11px] text-slate-500">
                                {d.phone || '—'}
                                {d.city ? ` · ${d.city}` : ''} · {d.missions_in_progress} en cours
                            </span>
                        </span>
                    </button>
                </li>
            ))}
        </ul>
    );
}
