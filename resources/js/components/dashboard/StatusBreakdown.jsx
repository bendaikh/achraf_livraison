import { Cell, Pie, PieChart, ResponsiveContainer } from 'recharts';
import { Card, EmptyState } from '../ui';

export default function StatusBreakdown({ items }) {
    const data = (items || []).filter((i) => i.count > 0);
    const total = data.reduce((s, i) => s + i.count, 0);
    return (
        <Card title="Répartition par statut" subtitle="Statuts configurés · période sélectionnée">
            {total === 0 ? (
                <EmptyState>Aucune donnée</EmptyState>
            ) : (
                <div className="flex flex-col items-center gap-4 sm:flex-row lg:flex-col xl:flex-row">
                    <div className="relative h-[150px] w-[150px] shrink-0">
                        <ResponsiveContainer width="100%" height="100%">
                            <PieChart>
                                <Pie data={data} dataKey="count" nameKey="name" innerRadius={48} outerRadius={70} paddingAngle={2} stroke="none">
                                    {data.map((e) => (
                                        <Cell key={e.id} fill={e.color} />
                                    ))}
                                </Pie>
                            </PieChart>
                        </ResponsiveContainer>
                        <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                            <div className="text-xl font-bold text-slate-900">{total}</div>
                            <div className="text-[10px] font-semibold uppercase text-slate-400">Total</div>
                        </div>
                    </div>
                    <ul className="w-full space-y-1.5">
                        {data.map((i) => (
                            <li key={i.id} className="flex items-center justify-between gap-2 text-sm">
                                <span className="flex items-center gap-2 text-slate-600">
                                    <span className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: i.color }} />
                                    {i.name}
                                </span>
                                <span className="font-semibold text-slate-800">
                                    {i.count} <span className="text-xs font-medium text-slate-400">({Math.round((i.count * 100) / total)} %)</span>
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </Card>
    );
}
