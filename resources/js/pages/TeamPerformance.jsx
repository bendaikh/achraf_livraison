import { useEffect, useState } from 'react';
import api, { errorMessage } from '../lib/api';
import { formatDH } from '../lib/format';
import { Alert, Card, EmptyState, PageHeader, Spinner } from '../components/ui';
import PeriodFilter from '../components/team/PeriodFilter';

const COLS = [
    ['assigned', 'Assignées'],
    ['processed', 'Traitées'],
    ['confirmed', 'Confirmées'],
    ['no_answer', 'Pas de réponse'],
    ['postponed', 'Reportées'],
    ['cancelled', 'Annulées'],
    ['delivered', 'Livrées'],
];

/** Équipe → Performance confirmation par agent (T6), real data only. */
export default function TeamPerformance() {
    const [filter, setFilter] = useState({ period: 'month' });
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);

    useEffect(() => {
        if (filter.period === 'custom' && (!filter.from || !filter.to)) return;
        setData(null);
        api.get('/team/performance', { params: filter })
            .then(({ data: d }) => setData(d))
            .catch((e) => setError(errorMessage(e)));
    }, [filter]);

    const pct = (v) => (v === null || v === undefined ? '—' : `${v} %`);

    return (
        <div className="space-y-4">
            <PageHeader title="Performance confirmation" subtitle={data && !data.can_view_all ? 'Vos statistiques personnelles' : 'Par agent, sur la période choisie'} actions={<PeriodFilter value={filter} onChange={setFilter} />} />
            <Alert>{error}</Alert>
            {!data ? (
                <Spinner />
            ) : data.rows.length === 0 ? (
                <Card>
                    <EmptyState>Aucun agent.</EmptyState>
                </Card>
            ) : (
                <Card bodyClassName="p-0">
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-left text-sm">
                            <thead>
                                <tr className="border-b border-slate-100 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                                    <th className="px-4 py-2.5">Agent</th>
                                    {COLS.map(([k, l]) => (
                                        <th key={k} className="px-2 py-2.5 text-right">
                                            {l}
                                        </th>
                                    ))}
                                    <th className="px-2 py-2.5 text-right">Taux confirm.</th>
                                    <th className="px-2 py-2.5 text-right">Taux livraison</th>
                                    <th className="px-2 py-2.5 text-right">Commissions</th>
                                    <th className="px-2 py-2.5 text-right">Validées</th>
                                    <th className="px-2 py-2.5 text-right">En attente</th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.rows.map((r) => (
                                    <tr key={r.user_id} className="border-b border-slate-50 last:border-0">
                                        <td className="px-4 py-2">
                                            <div className="font-semibold text-slate-800">{r.name}</div>
                                            <div className="text-[11px] text-slate-400">{[r.service, r.commission_mode_label].filter(Boolean).join(' · ')}</div>
                                        </td>
                                        {COLS.map(([k]) => (
                                            <td key={k} className={`px-2 py-2 text-right tabular-nums ${r[k] ? 'text-slate-800' : 'text-slate-300'}`}>
                                                {r[k]}
                                            </td>
                                        ))}
                                        <td className="px-2 py-2 text-right font-semibold text-slate-800">{pct(r.confirmation_rate)}</td>
                                        <td className="px-2 py-2 text-right font-semibold text-slate-800">{pct(r.delivery_rate)}</td>
                                        <td className="px-2 py-2 text-right font-semibold text-slate-800">{formatDH(r.commissions_generated)}</td>
                                        <td className="px-2 py-2 text-right text-blue-700">{formatDH(r.commissions_validated + r.commissions_paid)}</td>
                                        <td className="px-2 py-2 text-right text-amber-700">{formatDH(r.commissions_pending)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>
            )}
            <p className="text-[11px] text-slate-400">
                Traitées = commandes sur lesquelles l’agent a fait une action de confirmation. Livrées = commandes confirmées par l’agent sur la période et livrées depuis. Commissions = lignes générées sur la période (séparées des frais livreurs et du COD).
            </p>
        </div>
    );
}
