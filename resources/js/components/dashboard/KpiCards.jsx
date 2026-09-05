import {
    ShoppingBag,
    CheckCircle2,
    Truck,
    XCircle,
    TrendingUp,
    TrendingDown,
} from 'lucide-react';
import { kpiStats } from '../../data/dashboard';

const iconMap = {
    today: ShoppingBag,
    delivered: CheckCircle2,
    progress: Truck,
    canceled: XCircle,
};

const colorMap = {
    blue: {
        iconBg: 'bg-blue-50 text-blue-600',
        ring: 'ring-blue-100',
    },
    green: {
        iconBg: 'bg-emerald-50 text-emerald-600',
        ring: 'ring-emerald-100',
    },
    orange: {
        iconBg: 'bg-orange-50 text-orange-600',
        ring: 'ring-orange-100',
    },
    red: {
        iconBg: 'bg-rose-50 text-rose-600',
        ring: 'ring-rose-100',
    },
};

export default function KpiCards() {
    return (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            {kpiStats.map((stat) => {
                const Icon = iconMap[stat.id];
                const colors = colorMap[stat.color];
                const up = stat.trend === 'up';

                return (
                    <article
                        key={stat.id}
                        className="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm shadow-slate-200/40"
                    >
                        <div className="flex items-start justify-between gap-3">
                            <div
                                className={`flex h-11 w-11 items-center justify-center rounded-xl ring-1 ${colors.iconBg} ${colors.ring}`}
                            >
                                <Icon className="h-5 w-5" strokeWidth={2} />
                            </div>
                            <span
                                className={[
                                    'inline-flex items-center gap-1 rounded-full px-2 py-1 text-xs font-semibold',
                                    up ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700',
                                ].join(' ')}
                            >
                                {up ? (
                                    <TrendingUp className="h-3.5 w-3.5" />
                                ) : (
                                    <TrendingDown className="h-3.5 w-3.5" />
                                )}
                                {up ? '+' : '-'}
                                {stat.change}%
                            </span>
                        </div>
                        <div className="mt-4">
                            <div className="text-sm font-medium text-slate-500">{stat.title}</div>
                            <div className="mt-1 text-3xl font-bold tracking-tight text-slate-900">
                                {stat.value}
                            </div>
                            <div className="mt-1 text-xs font-medium text-slate-400">{stat.subtitle}</div>
                        </div>
                    </article>
                );
            })}
        </div>
    );
}
