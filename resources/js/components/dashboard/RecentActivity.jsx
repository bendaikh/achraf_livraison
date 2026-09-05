import { CheckCircle2, MessageCircle, Package, XCircle } from 'lucide-react';
import { recentActivity } from '../../data/dashboard';

const typeStyles = {
    order: {
        icon: Package,
        className: 'bg-blue-50 text-blue-600',
    },
    delivered: {
        icon: CheckCircle2,
        className: 'bg-emerald-50 text-emerald-600',
    },
    whatsapp: {
        icon: MessageCircle,
        className: 'bg-emerald-50 text-emerald-600',
    },
    canceled: {
        icon: XCircle,
        className: 'bg-rose-50 text-rose-600',
    },
};

export default function RecentActivity() {
    return (
        <section className="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm shadow-slate-200/40">
            <div className="mb-4">
                <h2 className="text-base font-bold text-slate-900">Activité récente</h2>
                <p className="text-xs font-medium text-slate-400">Derniers événements</p>
            </div>

            <ol className="relative space-y-0">
                {recentActivity.map((item, index) => {
                    const style = typeStyles[item.type];
                    const Icon = style.icon;
                    const isLast = index === recentActivity.length - 1;

                    return (
                        <li key={item.id} className="relative flex gap-3 pb-5 last:pb-0">
                            {!isLast ? (
                                <span className="absolute left-[17px] top-9 h-[calc(100%-20px)] w-px bg-slate-200" />
                            ) : null}
                            <div
                                className={`relative z-10 flex h-9 w-9 shrink-0 items-center justify-center rounded-full ${style.className}`}
                            >
                                <Icon className="h-4 w-4" strokeWidth={2.2} />
                            </div>
                            <div className="min-w-0 flex-1 pt-0.5">
                                <div className="text-sm font-semibold text-slate-800">{item.title}</div>
                                <div className="truncate text-xs font-medium text-slate-400">
                                    {item.detail}
                                </div>
                                <div className="mt-1 text-[11px] font-semibold text-slate-400">
                                    {item.time}
                                </div>
                            </div>
                        </li>
                    );
                })}
            </ol>
        </section>
    );
}
