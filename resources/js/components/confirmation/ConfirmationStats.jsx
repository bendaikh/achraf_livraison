import { useEffect, useState } from 'react';
import { CheckCircle2, ListChecks, PhoneCall, XCircle } from 'lucide-react';
import api from '../../lib/api';

/** Top cards of the Centre de confirmation — real counters (GET /api/confirmation/stats). */
export default function ConfirmationStats({ refreshKey = 0, compact = false, onLoaded }) {
    const [s, setS] = useState(null);

    useEffect(() => {
        api.get('/confirmation/stats')
            .then(({ data }) => {
                setS(data);
                onLoaded?.(data);
            })
            .catch(() => setS(null));
    }, [refreshKey, onLoaded]);

    const cards = [
        { key: 'calls', label: 'Appels aujourd’hui', icon: PhoneCall, color: '#2563eb', metric: s?.calls, mine: s?.mine?.calls_today },
        { key: 'to_confirm', label: 'À confirmer', icon: ListChecks, color: '#d97706', value: s?.to_confirm, sub: s?.postponed_due ? `dont ${s.postponed_due} rappel(s) échu(s)` : null },
        { key: 'confirmed', label: 'Confirmées', icon: CheckCircle2, color: '#16a34a', metric: s?.confirmed, mine: s?.mine?.confirmed_today },
        { key: 'failed', label: 'Échecs / annulées', icon: XCircle, color: '#dc2626', metric: s?.failed, mine: s?.mine?.failed_today },
    ];

    return (
        <div className={`grid grid-cols-2 gap-2 ${compact ? 'xl:grid-cols-4' : 'lg:grid-cols-4'}`}>
            {cards.map((c) => {
                const value = c.metric ? c.metric.today : c.value;
                const cmp = c.metric && c.metric.yesterday !== null && c.metric.yesterday !== undefined ? c.metric : null;
                return (
                    <div key={c.key} className="rounded-2xl border border-slate-200/80 bg-white px-3 py-2.5 shadow-sm shadow-slate-200/40" data-stat={c.key}>
                        <div className="flex items-center justify-between gap-2">
                            <span className="truncate text-[11px] font-semibold uppercase tracking-wide text-slate-400">{c.label}</span>
                            <c.icon className="h-4 w-4 shrink-0" style={{ color: c.color }} />
                        </div>
                        <div className="mt-0.5 text-2xl font-extrabold tabular-nums text-slate-900">{s ? (value ?? 0) : '…'}</div>
                        <div className="truncate text-[11px] text-slate-500">
                            {cmp ? `Hier ${cmp.yesterday} · 7 j ${cmp.last_7_days} · 30 j ${cmp.last_30_days}` : c.sub || (c.mine !== undefined && s ? `Moi : ${c.mine}` : '\u00a0')}
                        </div>
                    </div>
                );
            })}
            {s?.by_status?.length ? (
                <div className="col-span-2 rounded-2xl border border-slate-200/80 bg-white px-3 py-2.5 text-xs text-slate-600 shadow-sm lg:col-span-4">
                    <div className="flex flex-wrap gap-x-3 gap-y-1">
                        {s.by_status.filter((row) => row.count > 0).map((row) => (
                            <span key={row.code}>
                                <span className="font-semibold text-slate-800">{row.name}</span> : {row.count}
                            </span>
                        ))}
                        {s.other_statuses ? <span className="font-semibold text-slate-800">autres statuts : {s.other_statuses}</span> : null}
                    </div>
                    {s.recall ? (
                        <div className="mt-1 text-[11px] text-amber-800">
                            Rappels — En retard {s.recall.overdue ?? 0} · Aujourd’hui {s.recall.today ?? 0} · À venir {s.recall.upcoming ?? 0}
                        </div>
                    ) : null}
                </div>
            ) : null}
        </div>
    );
}
