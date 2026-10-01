import { RotateCcw } from 'lucide-react';
import { useMeta } from '../../context/MetaContext';
import { Button, Input, Select } from '../ui';

const PERIODS = [
    ['today', "Aujourd'hui"],
    ['yesterday', 'Hier'],
    ['week', 'Cette semaine'],
    ['month', 'Ce mois'],
    ['custom', 'Personnalisée'],
];

export const DEFAULT_FILTERS = { period: 'today', from: '', to: '', driver_id: '' };

export default function DashboardFilters({ filters, onChange }) {
    const { drivers } = useMeta();
    const set = (patch) => onChange({ ...filters, ...patch });
    const isDefault = filters.period === 'today' && !filters.driver_id;

    return (
        <div className="flex flex-col gap-2 rounded-2xl border border-slate-200/80 bg-white p-2.5 shadow-sm lg:flex-row lg:items-center">
            <div className="flex gap-1 overflow-x-auto rounded-xl bg-slate-100 p-1">
                {PERIODS.map(([key, label]) => (
                    <button
                        key={key}
                        type="button"
                        onClick={() => set({ period: key })}
                        className={`shrink-0 rounded-lg px-3 py-1.5 text-xs font-semibold transition sm:text-sm ${filters.period === key ? 'bg-white text-blue-700 shadow-sm' : 'text-slate-500 hover:text-slate-800'}`}
                    >
                        {label}
                    </button>
                ))}
            </div>
            {filters.period === 'custom' ? (
                <div className="grid grid-cols-2 gap-2">
                    <Input type="date" value={filters.from} onChange={(e) => set({ from: e.target.value })} aria-label="Du" className="h-9" />
                    <Input type="date" value={filters.to} onChange={(e) => set({ to: e.target.value })} aria-label="Au" className="h-9" />
                </div>
            ) : null}
            <div className="flex gap-2 lg:ml-auto">
                <Select value={filters.driver_id} onChange={(e) => set({ driver_id: e.target.value })} className="h-9 lg:w-52" aria-label="Livreur">
                    <option value="">Tous les livreurs</option>
                    {drivers.map((d) => (
                        <option key={d.id} value={d.id}>
                            {d.name}
                        </option>
                    ))}
                </Select>
                <Button size="sm" variant="secondary" className="h-9 shrink-0" disabled={isDefault} onClick={() => onChange(DEFAULT_FILTERS)}>
                    <RotateCcw className="h-3.5 w-3.5" />
                    <span className="hidden sm:inline">Réinitialiser les filtres</span>
                </Button>
            </div>
        </div>
    );
}
