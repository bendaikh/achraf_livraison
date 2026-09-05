import { Cell, Pie, PieChart, ResponsiveContainer } from 'recharts';
import { statusBreakdown } from '../../data/dashboard';

export default function StatusDonut() {
    const total = statusBreakdown.reduce((sum, item) => sum + item.value, 0);

    return (
        <section className="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm shadow-slate-200/40">
            <div className="mb-2">
                <h2 className="text-base font-bold text-slate-900">Statut des commandes</h2>
                <p className="text-xs font-medium text-slate-400">Répartition du jour</p>
            </div>

            <div className="relative mx-auto h-[180px] w-full max-w-[200px]">
                <ResponsiveContainer width="100%" height="100%">
                    <PieChart>
                        <Pie
                            data={statusBreakdown}
                            dataKey="value"
                            nameKey="name"
                            innerRadius={58}
                            outerRadius={78}
                            paddingAngle={3}
                            stroke="none"
                        >
                            {statusBreakdown.map((entry) => (
                                <Cell key={entry.name} fill={entry.color} />
                            ))}
                        </Pie>
                    </PieChart>
                </ResponsiveContainer>
                <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                    <div className="text-2xl font-bold text-slate-900">{total}</div>
                    <div className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                        Total
                    </div>
                </div>
            </div>

            <ul className="mt-2 space-y-2.5">
                {statusBreakdown.map((item) => (
                    <li key={item.name} className="flex items-center justify-between gap-3 text-sm">
                        <div className="flex items-center gap-2 font-medium text-slate-600">
                            <span
                                className="h-2.5 w-2.5 rounded-full"
                                style={{ backgroundColor: item.color }}
                            />
                            {item.name}
                        </div>
                        <div className="font-bold text-slate-800">{item.percent}%</div>
                    </li>
                ))}
            </ul>
        </section>
    );
}
