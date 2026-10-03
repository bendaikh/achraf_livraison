import { Ban } from 'lucide-react';
import { formatDate } from '../../lib/format';

export function SegmentBadges({ keys = [], segments = [] }) {
    const byKey = Object.fromEntries(segments.map((s) => [s.key, s]));
    return (
        <div className="flex flex-wrap gap-1">
            {keys.map((k) =>
                byKey[k] ? (
                    <span key={k} className="rounded-md px-1.5 py-0.5 text-[10px] font-semibold" style={{ background: `${byKey[k].color}15`, color: byKey[k].color }}>
                        {byKey[k].label}
                    </span>
                ) : null,
            )}
        </div>
    );
}

/** Red alert shown wherever a blocked client's order appears (Commandes, Confirmation, fiche). */
export function BlockedClientAlert({ block, compact = false }) {
    if (!block) return null;
    if (compact) {
        return (
            <span className="inline-flex items-center gap-1 rounded-md bg-rose-50 px-1.5 py-0.5 text-[10px] font-bold text-rose-700" title={`Client bloqué : ${block.reason}${block.comment ? ` — ${block.comment}` : ''}`}>
                <Ban className="h-3 w-3" /> Client bloqué
            </span>
        );
    }
    return (
        <div className="flex items-start gap-2 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2.5 text-sm text-rose-800">
            <Ban className="mt-0.5 h-4 w-4 shrink-0" />
            <div>
                <div className="font-bold">Client bloqué — {block.reason}</div>
                <div className="text-xs text-rose-700">
                    {[block.comment, block.blocked_by_name && `par ${block.blocked_by_name}`, block.blocked_at && `le ${formatDate(block.blocked_at)}`].filter(Boolean).join(' · ')}
                </div>
            </div>
        </div>
    );
}
