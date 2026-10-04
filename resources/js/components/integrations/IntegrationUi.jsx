/** Shared bits of the carrier integration pages (Ozon Express, Sift.ma). */
import { NavLink, useLocation } from 'react-router-dom';

const STATE_BADGE = {
    connecte: ['Connecté', 'bg-emerald-50 text-emerald-700 ring-emerald-200', 'bg-emerald-500'],
    non_teste: ['Non testé', 'bg-amber-50 text-amber-700 ring-amber-200', 'bg-amber-500'],
    erreur: ['Erreur de connexion', 'bg-rose-50 text-rose-700 ring-rose-200', 'bg-rose-500'],
    incomplet: ['Incomplet', 'bg-amber-50 text-amber-700 ring-amber-200', 'bg-amber-500'],
    inactif: ['Inactif', 'bg-slate-100 text-slate-600 ring-slate-200', 'bg-slate-400'],
};

export function Toggle({ checked, onChange, label, hint, disabled = false }) {
    return (
        <label className={`flex items-start gap-3 ${disabled ? 'opacity-60' : 'cursor-pointer'}`}>
            <button
                type="button"
                role="switch"
                aria-checked={!!checked}
                aria-label={label}
                disabled={disabled}
                onClick={() => onChange(!checked)}
                className={`relative mt-0.5 inline-flex h-6 w-11 shrink-0 items-center rounded-full transition ${checked ? 'bg-teal-600' : 'bg-slate-300'}`}
            >
                <span className={`inline-block h-5 w-5 transform rounded-full bg-white shadow transition ${checked ? 'translate-x-5' : 'translate-x-0.5'}`} />
            </button>
            <span>
                <span className="block text-sm font-semibold text-slate-800">{label}</span>
                {hint ? <span className="block text-xs text-slate-500">{hint}</span> : null}
            </span>
        </label>
    );
}

export function StateBadge({ state }) {
    const [label, cls, dot] = STATE_BADGE[state] || STATE_BADGE.inactif;
    return (
        <span className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset ${cls}`}>
            <span className={`h-2 w-2 rounded-full ${dot}`} />
            {label}
        </span>
    );
}

export function Stat({ label, value, hint }) {
    return (
        <div className="rounded-xl bg-slate-50 px-3 py-2">
            <dt className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{label}</dt>
            <dd className="mt-0.5 font-semibold text-slate-800">{value}</dd>
            {hint ? <dd className="text-[11px] text-slate-500">{hint}</dd> : null}
        </div>
    );
}

/** Paramètres → Transporteurs: switch between the carriers that have mappings. */
export function CarrierSettingsSwitch() {
    const { pathname } = useLocation();
    if (!pathname.startsWith('/parametres/transporteurs')) return null;
    const links = [
        ['/parametres/transporteurs/ozon', 'Ozon Express'],
        ['/parametres/transporteurs/sift', 'Sift.ma'],
    ];
    return (
        <div className="flex gap-1.5" aria-label="Transporteurs">
            {links.map(([to, label]) => (
                <NavLink key={to} to={to} className={({ isActive }) => `rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset transition ${isActive ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white text-slate-600 ring-slate-200 hover:text-slate-900'}`}>
                    {label}
                </NavLink>
            ))}
        </div>
    );
}
