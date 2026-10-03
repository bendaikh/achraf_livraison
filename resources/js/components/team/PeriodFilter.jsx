import { Input, Select } from '../ui';

export const PERIODS = [
    ['today', 'Aujourd’hui'],
    ['yesterday', 'Hier'],
    ['week', 'Cette semaine'],
    ['month', 'Ce mois-ci'],
    ['custom', 'Personnalisée'],
];

export default function PeriodFilter({ value, onChange }) {
    return (
        <div className="flex flex-wrap items-center gap-2">
            <Select value={value.period} onChange={(e) => onChange({ ...value, period: e.target.value })} aria-label="Période" className="max-w-44">
                {PERIODS.map(([v, l]) => (
                    <option key={v} value={v}>
                        {l}
                    </option>
                ))}
            </Select>
            {value.period === 'custom' ? (
                <>
                    <Input type="date" value={value.from || ''} onChange={(e) => onChange({ ...value, from: e.target.value })} aria-label="Du" className="max-w-40" />
                    <Input type="date" value={value.to || ''} onChange={(e) => onChange({ ...value, to: e.target.value })} aria-label="Au" className="max-w-40" />
                </>
            ) : null}
        </div>
    );
}
