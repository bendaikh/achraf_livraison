import { Link, useNavigate } from 'react-router-dom';
import { formatDH, formatTime, formatDate } from '../../lib/format';
import { Card, EmptyState } from '../ui';
import { StatusBadge, ColorBadge } from '../ui/Badge';
import { useMeta } from '../../context/MetaContext';

export default function RecentOrders({ orders, period }) {
    const navigate = useNavigate();
    const meta = useMeta();
    const timeOf = (iso) => (period?.key === 'today' ? formatTime(iso) : `${formatDate(iso)} ${formatTime(iso)}`);
    const status = (o) => (o.delivery_status ? <StatusBadge status={o.delivery_status} /> : <ColorBadge color={meta.confirmationMap[o.confirmation_status]?.color} label={meta.confirmationMap[o.confirmation_status]?.label} />);

    return (
        <Card
            title="Dernières commandes"
            bodyClassName="p-0"
            actions={
                <Link to="/commandes" className="text-xs font-semibold text-blue-600 hover:text-blue-700">
                    Voir tout
                </Link>
            }
        >
            {!orders?.length ? (
                <EmptyState>Aucune donnée</EmptyState>
            ) : (
                <ul className="divide-y divide-slate-100">
                    {orders.map((o) => (
                        <li key={o.id}>
                            <button type="button" onClick={() => navigate(`/commandes/${o.id}`)} className="flex w-full items-center gap-3 px-4 py-2.5 text-left hover:bg-slate-50 sm:px-5">
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <span className="text-sm font-bold text-slate-800">{o.reference}</span>
                                        <span className="text-[11px] text-slate-400">{timeOf(o.created_at)}</span>
                                    </div>
                                    <div className="truncate text-xs text-slate-500">
                                        {o.customer_name} · {o.city || '—'}
                                    </div>
                                </div>
                                <div className="flex flex-col items-end gap-1">
                                    <span className="text-sm font-semibold text-slate-800">{formatDH(o.amount)}</span>
                                    {status(o)}
                                </div>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}
