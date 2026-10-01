import { Link } from 'react-router-dom';
import { AlertTriangle, ChevronRight } from 'lucide-react';
import { Card, EmptyState } from '../ui';

export default function AlertsPanel({ alerts }) {
    const active = (alerts || []).filter((a) => a.count > 0);
    return (
        <Card title="À traiter" subtitle={active.length ? `${active.length} point(s) d’attention` : 'Tout est à jour'} bodyClassName="p-2">
            {active.length === 0 ? (
                <EmptyState>Rien à traiter pour le moment</EmptyState>
            ) : (
                <ul className="space-y-1">
                    {active.map((a) => (
                        <li key={a.key}>
                            <Link to={a.link} className="flex items-start gap-3 rounded-xl px-3 py-2.5 hover:bg-amber-50/60">
                                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-500" />
                                <div className="min-w-0 flex-1">
                                    <div className="text-sm font-semibold text-slate-800">{a.label}</div>
                                    {a.details?.length ? <div className="truncate text-xs text-slate-500">{a.details.join(' · ')}</div> : null}
                                </div>
                                <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-800">{a.count}</span>
                                <ChevronRight className="mt-0.5 h-4 w-4 text-slate-300" />
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}
