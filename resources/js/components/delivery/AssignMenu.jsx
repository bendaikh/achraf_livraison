import { useEffect, useMemo, useRef, useState } from 'react';
import { Search, UserRound } from 'lucide-react';
import { useMeta } from '../../context/MetaContext';
import useDeliveryModes from '../../hooks/useDeliveryModes';
import { fold } from '../../lib/delivery';
import DriverList from './DriverList';

/**
 * « Affecter à › » — Livraison locale (active livreurs) and agents.
 * Livreurs locaux are the only driver list: there is no second « Livreur » section.
 */
export default function AssignMenu({ onDriver, onAgent, onClose }) {
    const data = useDeliveryModes();
    const meta = useMeta();
    const [query, setQuery] = useState('');
    const input = useRef(null);
    const q = fold(query);

    useEffect(() => {
        input.current?.focus();
    }, []);

    useEffect(() => {
        const onKey = (e) => e.key === 'Escape' && onClose?.();
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    const agents = useMemo(() => {
        const list = [...(meta.users || []).map((u) => ({ id: u.id, name: u.name })), { id: null, name: 'Aucun agent' }];
        return list.filter((a) => !q || fold(a.name).includes(q));
    }, [meta.users, q]);

    return (
        <div className="w-72 rounded-xl border border-slate-200 bg-white p-2 shadow-lg" onKeyDown={(e) => e.key === 'Escape' && onClose?.()}>
            <div className="relative mb-1.5">
                <Search className="pointer-events-none absolute left-2 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" />
                <input
                    ref={input}
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder="Rechercher"
                    aria-label="Rechercher un livreur ou un agent"
                    className="h-8 w-full rounded-lg border border-slate-200 bg-slate-50 pl-7 pr-2 text-xs outline-none focus:border-blue-300"
                />
            </div>
            {data.can_assign_driver ? (
                <section className="mb-2">
                    <div className="px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-slate-400">Livraison locale</div>
                    <DriverList query={query} onPick={onDriver} />
                </section>
            ) : null}
            {data.can_assign_agent ? (
                <section>
                    <div className="px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-slate-400">Agent</div>
                    <ul className="max-h-40 overflow-y-auto" role="listbox" aria-label="Agents">
                        {agents.map((a) => (
                            <li key={a.id ?? 'none'}>
                                <button type="button" onClick={() => onAgent(a.id)} className="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm hover:bg-slate-50">
                                    <UserRound className="h-3.5 w-3.5 text-slate-400" />
                                    <span className="truncate font-semibold text-slate-800">{a.name}</span>
                                </button>
                            </li>
                        ))}
                    </ul>
                </section>
            ) : null}
            {!data.can_assign_driver && !data.can_assign_agent ? <p className="px-2 py-2 text-xs text-slate-400">Aucune affectation autorisée.</p> : null}
        </div>
    );
}
