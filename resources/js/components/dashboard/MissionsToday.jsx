import { Link } from 'react-router-dom';
import { CheckCircle2, PackagePlus, Repeat, Warehouse } from 'lucide-react';
import { Card } from '../ui';

export default function MissionsToday({ missions, period, driverId }) {
    const range = `date_from=${period.from}&date_to=${period.to}${driverId ? `&driver_id=${driverId}` : ''}`;
    const items = [
        { key: 'pickups_todo', label: 'Ramassages à faire', icon: PackagePlus, color: '#2563eb', to: `/missions?type=ramassage&status=open&${range}` },
        { key: 'deposits_todo', label: 'Dépôts partenaires à faire', icon: Warehouse, color: '#7c3aed', to: `/missions?type=depot_partenaire&status=open&${range}` },
        { key: 'returns_todo', label: 'Retours / échanges', icon: Repeat, color: '#ea580c', to: `/missions?type=retour,echange&status=open&${range}` },
        // Completed missions are counted on completion date (any scheduled date).
        { key: 'completed', label: 'Missions terminées', icon: CheckCircle2, color: '#16a34a', to: `/missions?status=terminee${driverId ? `&driver_id=${driverId}` : ''}` },
    ];
    return (
        <Card title={period.key === 'today' ? 'Missions du jour' : 'Missions de la période'} bodyClassName="grid grid-cols-2 gap-2 p-3 sm:p-4">
            {items.map(({ key, label, icon: Icon, color, to }) => (
                <Link key={key} to={to} className="rounded-xl border border-slate-100 p-3 transition hover:border-blue-200 hover:bg-blue-50/40">
                    <Icon className="h-4 w-4" style={{ color }} />
                    <div className="mt-1.5 text-xl font-bold text-slate-900">{missions?.[key] ?? 0}</div>
                    <div className="text-[11px] font-semibold leading-tight text-slate-500">{label}</div>
                </Link>
            ))}
        </Card>
    );
}
