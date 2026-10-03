import { formatDateTime } from '../../lib/format';

export default function SavTimeline({ history = [] }) {
    if (!history.length) return <p className="text-sm text-slate-400">Aucun historique.</p>;
    return (
        <ol className="relative space-y-3 border-l border-slate-200 pl-4">
            {history.map((h) => (
                <li key={h.id} className="relative">
                    <span className="absolute -left-[21px] top-1 h-2.5 w-2.5 rounded-full ring-2 ring-white" style={{ background: h.to_color }} />
                    <div className="text-sm font-semibold text-slate-800">
                        {h.label}
                        {h.event === 'status' && h.from_label && h.from_label !== h.to_label ? <span className="ml-1 text-xs font-normal text-slate-400">({h.from_label} → {h.to_label})</span> : null}
                    </div>
                    {h.comment ? <div className="text-xs text-slate-600">{h.comment}</div> : null}
                    <div className="text-[11px] text-slate-400">
                        {formatDateTime(h.created_at)} · {h.by || 'Système'}
                    </div>
                </li>
            ))}
        </ol>
    );
}
