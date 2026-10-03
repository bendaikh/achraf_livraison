import { Link } from 'react-router-dom';
import { formatDH } from '../../lib/format';
import { formatHistoryDate } from '../../pages/confirmationHelpers';

export const STATE_STYLE = {
    pending: 'bg-amber-50 text-amber-700',
    validated: 'bg-blue-50 text-blue-700',
    paid: 'bg-emerald-50 text-emerald-700',
    cancelled: 'bg-slate-100 text-slate-500 line-through',
};

/** Commission history lines (order link, status, amount, state) — agent card & Équipe → Commissions. */
export default function CommissionLines({ lines, showAgent = false, selectable = false, selected = [], onToggle }) {
    if (!lines?.length) return <p className="py-6 text-center text-sm text-slate-400">Aucune commission sur la période.</p>;
    return (
        <div className="overflow-x-auto">
            <table className="min-w-full text-left text-sm">
                <thead>
                    <tr className="border-b border-slate-100 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                        {selectable ? <th className="w-8 py-2 pl-3" /> : null}
                        <th className="px-2 py-2">Date</th>
                        {showAgent ? <th className="px-2 py-2">Agent</th> : null}
                        <th className="px-2 py-2">Commande</th>
                        <th className="px-2 py-2">Statut commande</th>
                        <th className="px-2 py-2">Règle</th>
                        <th className="px-2 py-2 text-right">Montant</th>
                        <th className="px-2 py-2">État</th>
                    </tr>
                </thead>
                <tbody>
                    {lines.map((l) => (
                        <tr key={l.id} className="border-b border-slate-50 last:border-0">
                            {selectable ? (
                                <td className="py-1.5 pl-3">
                                    <input type="checkbox" checked={selected.includes(l.id)} onChange={() => onToggle(l.id)} disabled={['paid', 'cancelled'].includes(l.state)} aria-label={`Sélectionner la commission ${l.id}`} />
                                </td>
                            ) : null}
                            <td className="whitespace-nowrap px-2 py-1.5 text-xs text-slate-500">{formatHistoryDate(l.generated_at)}</td>
                            {showAgent ? <td className="whitespace-nowrap px-2 py-1.5 font-medium text-slate-700">{l.user_name}</td> : null}
                            <td className="whitespace-nowrap px-2 py-1.5">
                                {l.order_id ? (
                                    <Link to={`/commandes/${l.order_id}`} className="font-semibold text-blue-700 hover:underline">
                                        {l.order_reference}
                                    </Link>
                                ) : (
                                    <span className="text-slate-600">Fixe {l.period}</span>
                                )}
                            </td>
                            <td className="whitespace-nowrap px-2 py-1.5 text-xs text-slate-500">{l.order_status || '—'}</td>
                            <td className="whitespace-nowrap px-2 py-1.5 text-xs text-slate-500" title={l.trigger_label || ''}>
                                {l.mode_label}
                                {l.mode === 'percent' ? ` (${l.rate} %)` : ''}
                            </td>
                            <td className="whitespace-nowrap px-2 py-1.5 text-right font-semibold text-slate-800">{formatDH(l.amount)}</td>
                            <td className="whitespace-nowrap px-2 py-1.5">
                                <span className={`rounded-md px-1.5 py-0.5 text-[11px] font-semibold ${STATE_STYLE[l.state] || ''}`} title={[l.validated_at && `Validée le ${formatHistoryDate(l.validated_at)} par ${l.validated_by_name}`, l.paid_at && `Payée le ${formatHistoryDate(l.paid_at)} par ${l.paid_by_name}`, l.cancel_reason].filter(Boolean).join(' · ')}>
                                    {l.state_label}
                                </span>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
