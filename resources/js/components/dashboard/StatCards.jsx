import { Ban, CalendarClock, CheckCircle2, ClipboardCheck, Inbox, PackageCheck, PhoneOff, Truck, UserPlus } from 'lucide-react';

const CARDS = [
    { key: 'received', label: 'Commandes reçues', icon: Inbox, color: '#2563eb' },
    { key: 'to_confirm', label: 'À confirmer', icon: ClipboardCheck, color: '#f59e0b' },
    { key: 'confirmed', label: 'Confirmées', icon: CheckCircle2, color: '#16a34a' },
    { key: 'no_answer', label: 'Pas de réponse', icon: PhoneOff, color: '#a855f7' },
    { key: 'postponed', label: 'Reportées', icon: CalendarClock, color: '#0ea5e9' },
    { key: 'cancelled', label: 'Annulées', icon: Ban, color: '#e11d48' },
    { key: 'to_assign', label: 'À attribuer', icon: UserPlus, color: '#64748b' },
    { key: 'in_delivery', label: 'En livraison', icon: Truck, color: '#6366f1' },
    { key: 'delivered', label: 'Livrées', icon: PackageCheck, color: '#059669' },
];

export default function StatCards({ cards }) {
    return (
        <div className="grid grid-cols-3 gap-2 sm:gap-3 lg:grid-cols-5 2xl:grid-cols-9">
            {CARDS.map(({ key, label, icon: Icon, color }) => (
                <article key={key} className="rounded-xl border border-slate-200/80 bg-white p-2.5 shadow-sm sm:rounded-2xl sm:p-4">
                    <div className="flex items-center gap-2">
                        <span className="hidden h-8 w-8 shrink-0 items-center justify-center rounded-lg sm:flex" style={{ backgroundColor: `${color}14`, color }}>
                            <Icon className="h-4 w-4" />
                        </span>
                        <span className="text-[11px] font-semibold leading-tight text-slate-500 sm:text-xs">{label}</span>
                    </div>
                    <div className="mt-1.5 text-xl font-bold tracking-tight text-slate-900 sm:mt-2 sm:text-2xl">{cards?.[key] ?? 0}</div>
                </article>
            ))}
        </div>
    );
}
