import { BRAND } from '../../lib/brand';

/** Brand mark: icon + "Lav'Fast" with "Flow" on the second line. */
export function BrandIcon({ className = 'h-10 w-10' }) {
    return (
        <div className={`flex shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-blue-400 to-blue-700 shadow-lg shadow-blue-900/40 ${className}`}>
            <div className="h-4 w-4 rotate-12 rounded-sm bg-white/95 shadow-sm" />
        </div>
    );
}

export default function BrandLogo({ tone = 'dark', size = 'md', badge = null }) {
    const main = tone === 'dark' ? 'text-white' : 'text-slate-900';
    const sub = tone === 'dark' ? 'text-blue-300' : 'text-blue-600';
    const mainSize = size === 'lg' ? 'text-3xl sm:text-4xl' : 'text-lg';
    const subSize = size === 'lg' ? 'text-base' : 'text-[12px]';
    return (
        <div className="min-w-0 leading-none" aria-label={BRAND.title}>
            <div className={`truncate font-extrabold tracking-tight ${main} ${mainSize}`}>{BRAND.name}</div>
            <div className={`mt-1 flex items-center gap-1.5 ${size === 'lg' ? 'justify-center' : ''}`}>
                <span className={`font-semibold tracking-wide ${sub} ${subSize}`}>{BRAND.suffix}</span>
                {badge ? <span className="truncate rounded-full bg-slate-700/70 px-1.5 py-0.5 text-[10px] font-medium text-slate-300">{badge}</span> : null}
            </div>
        </div>
    );
}
