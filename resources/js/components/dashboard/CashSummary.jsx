import { Link } from 'react-router-dom';
import { formatDH } from '../../lib/format';
import { Card } from '../ui';

export default function CashSummary({ cash }) {
    const row = (label, value, strong) => (
        <div className="flex items-center justify-between gap-2 py-1.5 text-sm">
            <span className="text-slate-500">{label}</span>
            <span className={strong ? 'font-bold text-slate-900' : 'font-semibold text-slate-700'}>{formatDH(value)}</span>
        </div>
    );
    return (
        <Card
            title="COD / Caisse"
            subtitle="Argent des commandes, séparé de la rémunération des livreurs"
            actions={
                <Link to="/cloture" className="text-xs font-semibold text-blue-600 hover:text-blue-700">
                    Clôture
                </Link>
            }
        >
            <div className="divide-y divide-slate-100">
                {row('COD encaissé (période)', cash?.cod_collected, true)}
                {row('COD actuellement chez les livreurs', cash?.cod_with_drivers)}
                {row('Montant clôturé (période)', cash?.closed_amount)}
                {row('Montant restant à remettre', cash?.remaining_to_remit, true)}
            </div>
            <div className="mt-3 rounded-xl bg-slate-50 p-3">
                <div className="text-[10px] font-bold uppercase tracking-wide text-slate-400">Rémunération livreurs (hors COD)</div>
                <div className="mt-1 flex justify-between text-sm">
                    <span className="text-slate-500">Gagnée sur la période</span>
                    <span className="font-semibold text-slate-800">{formatDH(cash?.commissions_earned)}</span>
                </div>
                <div className="flex justify-between text-sm">
                    <span className="text-slate-500">Non clôturée</span>
                    <span className="font-semibold text-slate-800">{formatDH(cash?.commissions_unclosed)}</span>
                </div>
            </div>
        </Card>
    );
}
