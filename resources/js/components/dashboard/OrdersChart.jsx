import {
    Area,
    AreaChart,
    CartesianGrid,
    Legend,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { ChevronDown } from 'lucide-react';
import { chartData } from '../../data/dashboard';

function ChartTooltip({ active, payload, label }) {
    if (!active || !payload?.length) return null;

    return (
        <div className="rounded-xl border border-slate-200 bg-white px-3 py-2 shadow-lg">
            <div className="mb-1 text-xs font-semibold text-slate-500">{label}</div>
            {payload.map((entry) => (
                <div key={entry.dataKey} className="flex items-center gap-2 text-xs font-medium text-slate-700">
                    <span className="h-2 w-2 rounded-full" style={{ background: entry.color }} />
                    {entry.name}: {entry.value}
                </div>
            ))}
        </div>
    );
}

export default function OrdersChart() {
    return (
        <section className="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm shadow-slate-200/40">
            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-base font-bold text-slate-900">Évolution des commandes</h2>
                    <p className="text-xs font-medium text-slate-400">Performance sur 7 jours</p>
                </div>
                <button
                    type="button"
                    className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-600"
                >
                    7 derniers jours
                    <ChevronDown className="h-3.5 w-3.5" />
                </button>
            </div>

            <div className="h-[220px] w-full sm:h-[260px]">
                <ResponsiveContainer width="100%" height="100%">
                    <AreaChart data={chartData} margin={{ top: 8, right: 8, left: -18, bottom: 0 }}>
                        <defs>
                            <linearGradient id="totalFill" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stopColor="#2563eb" stopOpacity={0.25} />
                                <stop offset="100%" stopColor="#2563eb" stopOpacity={0} />
                            </linearGradient>
                            <linearGradient id="deliveredFill" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stopColor="#22c55e" stopOpacity={0.2} />
                                <stop offset="100%" stopColor="#22c55e" stopOpacity={0} />
                            </linearGradient>
                            <linearGradient id="canceledFill" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stopColor="#ef4444" stopOpacity={0.15} />
                                <stop offset="100%" stopColor="#ef4444" stopOpacity={0} />
                            </linearGradient>
                        </defs>
                        <CartesianGrid stroke="#e2e8f0" strokeDasharray="4 4" vertical={false} />
                        <XAxis
                            dataKey="day"
                            axisLine={false}
                            tickLine={false}
                            tick={{ fill: '#94a3b8', fontSize: 12, fontWeight: 500 }}
                        />
                        <YAxis
                            axisLine={false}
                            tickLine={false}
                            tick={{ fill: '#94a3b8', fontSize: 12, fontWeight: 500 }}
                        />
                        <Tooltip content={<ChartTooltip />} />
                        <Legend
                            verticalAlign="top"
                            align="right"
                            iconType="circle"
                            wrapperStyle={{ paddingBottom: 12, fontSize: 12, fontWeight: 600 }}
                        />
                        <Area
                            type="monotone"
                            dataKey="total"
                            name="Total"
                            stroke="#2563eb"
                            strokeWidth={2.5}
                            fill="url(#totalFill)"
                            dot={false}
                            activeDot={{ r: 4 }}
                        />
                        <Area
                            type="monotone"
                            dataKey="delivered"
                            name="Livrées"
                            stroke="#22c55e"
                            strokeWidth={2.5}
                            fill="url(#deliveredFill)"
                            dot={false}
                            activeDot={{ r: 4 }}
                        />
                        <Area
                            type="monotone"
                            dataKey="canceled"
                            name="Annulées"
                            stroke="#ef4444"
                            strokeWidth={2.5}
                            fill="url(#canceledFill)"
                            dot={false}
                            activeDot={{ r: 4 }}
                        />
                    </AreaChart>
                </ResponsiveContainer>
            </div>
        </section>
    );
}
