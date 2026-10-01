const RATES = [
    { key: 'confirmation', label: 'Taux de confirmation', hint: 'Confirmées / commandes traitées', color: '#16a34a' },
    { key: 'delivery', label: 'Taux de livraison', hint: 'Livrées / sorties en livraison', color: '#2563eb' },
    { key: 'failure', label: "Taux d'échec", hint: 'Échecs + retours / sorties en livraison', color: '#dc2626' },
];

export default function RatesCards({ rates }) {
    return (
        <div className="grid gap-2 sm:grid-cols-3 sm:gap-3">
            {RATES.map(({ key, label, hint, color }) => {
                const r = rates?.[key];
                return (
                    <article key={key} className="rounded-2xl border border-slate-200/80 bg-white p-3 shadow-sm sm:p-4">
                        <div className="flex items-baseline justify-between gap-2">
                            <span className="text-xs font-semibold text-slate-500">{label}</span>
                            <span className="text-xl font-bold text-slate-900">{r ? `${r.value} %` : '—'}</span>
                        </div>
                        <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100">
                            <div className="h-full rounded-full" style={{ width: `${Math.min(100, r?.value || 0)}%`, backgroundColor: color }} />
                        </div>
                        <div className="mt-1.5 text-[11px] text-slate-400">{r ? `${r.numerator} / ${r.denominator} · ${hint}` : 'Aucune donnée'}</div>
                    </article>
                );
            })}
        </div>
    );
}
