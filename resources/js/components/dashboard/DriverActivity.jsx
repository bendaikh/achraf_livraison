import { Link } from 'react-router-dom';
import { formatDH } from '../../lib/format';
import { Card, EmptyState } from '../ui';

const COLS = [
    ['assigned', 'Attribuées'],
    ['in_progress', 'En cours'],
    ['delivered', 'Livrées'],
    ['postponed', 'Reportées'],
    ['no_answer', 'Pas de rép.'],
    ['failed', 'Échouées'],
];

export default function DriverActivity({ drivers }) {
    return (
        <Card title="Activité des livreurs" subtitle="Commandes de la période · COD et caisse en temps réel" bodyClassName="p-0">
            {!drivers?.length ? (
                <EmptyState>Aucune donnée</EmptyState>
            ) : (
                <>
                    <div className="divide-y divide-slate-100 md:hidden">
                        {drivers.map((d) => (
                            <Link key={d.id} to={`/missions?driver_id=${d.id}`} className="block px-4 py-3">
                                <div className="flex items-center justify-between">
                                    <span className="font-semibold text-slate-800">{d.name}</span>
                                    {d.cash_unclosed ? (
                                        <span className="rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-bold text-amber-700">Caisse non clôturée</span>
                                    ) : (
                                        <span className="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-bold text-emerald-700">Caisse à jour</span>
                                    )}
                                </div>
                                <div className="mt-1 grid grid-cols-6 gap-1 text-center">
                                    {COLS.map(([k, l]) => (
                                        <div key={k}>
                                            <div className="text-sm font-bold text-slate-800">{d[k]}</div>
                                            <div className="text-[9px] leading-tight text-slate-400">{l}</div>
                                        </div>
                                    ))}
                                </div>
                                <div className="mt-1 text-xs text-slate-500">COD détenu : <b className="text-slate-700">{formatDH(d.cod_held)}</b></div>
                            </Link>
                        ))}
                    </div>
                    <div className="hidden overflow-x-auto md:block">
                        <table className="min-w-full text-left text-sm">
                            <thead>
                                <tr className="border-b border-slate-100 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                                    <th className="px-4 py-3">Livreur</th>
                                    {COLS.map(([k, l]) => (
                                        <th key={k} className="px-2 py-3 text-center">
                                            {l}
                                        </th>
                                    ))}
                                    <th className="px-2 py-3 text-right">Gains période</th>
                                    <th className="px-2 py-3 text-right">COD détenu</th>
                                    <th className="px-4 py-3">Caisse</th>
                                </tr>
                            </thead>
                            <tbody>
                                {drivers.map((d) => (
                                    <tr key={d.id} className="border-b border-slate-50 last:border-0 hover:bg-slate-50/70">
                                        <td className="whitespace-nowrap px-4 py-3 font-semibold">
                                            <Link to={`/missions?driver_id=${d.id}`} className="text-slate-800 hover:text-blue-700">
                                                {d.name}
                                            </Link>
                                        </td>
                                        {COLS.map(([k]) => (
                                            <td key={k} className="px-2 py-3 text-center font-semibold text-slate-700">
                                                {d[k]}
                                            </td>
                                        ))}
                                        <td className="whitespace-nowrap px-2 py-3 text-right text-slate-600">{formatDH(d.earnings)}</td>
                                        <td className="whitespace-nowrap px-2 py-3 text-right font-semibold text-slate-800">{formatDH(d.cod_held)}</td>
                                        <td className="whitespace-nowrap px-4 py-3">
                                            {d.cash_unclosed ? (
                                                <Link to="/cloture" className="rounded-full bg-amber-50 px-2 py-1 text-[11px] font-bold text-amber-700">
                                                    Non clôturée
                                                </Link>
                                            ) : (
                                                <span className="rounded-full bg-emerald-50 px-2 py-1 text-[11px] font-bold text-emerald-700">À jour</span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </>
            )}
        </Card>
    );
}
