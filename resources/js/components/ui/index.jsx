import { useEffect } from 'react';
import { X } from 'lucide-react';

export function Card({ title, subtitle, actions, children, className = '', bodyClassName = 'p-4 sm:p-5' }) {
    return (
        <section className={`rounded-2xl border border-slate-200/80 bg-white shadow-sm shadow-slate-200/40 ${className}`}>
            {title || actions ? (
                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-3 sm:px-5">
                    <div className="min-w-0">
                        {title ? <h2 className="text-base font-bold text-slate-900">{title}</h2> : null}
                        {subtitle ? <p className="text-xs font-medium text-slate-400">{subtitle}</p> : null}
                    </div>
                    {actions ? <div className="flex items-center gap-2">{actions}</div> : null}
                </div>
            ) : null}
            <div className={bodyClassName}>{children}</div>
        </section>
    );
}

export function PageHeader({ title, subtitle, actions }) {
    return (
        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div className="min-w-0">
                <h1 className="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">{title}</h1>
                {subtitle ? <p className="mt-1 text-sm font-medium text-slate-500">{subtitle}</p> : null}
            </div>
            {actions ? <div className="flex flex-wrap items-center gap-2">{actions}</div> : null}
        </div>
    );
}

const variants = {
    primary: 'bg-blue-600 text-white shadow-lg shadow-blue-600/20 hover:bg-blue-700',
    secondary: 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-50',
    danger: 'bg-rose-600 text-white hover:bg-rose-700',
    ghost: 'text-slate-600 hover:bg-slate-100',
};

export function Button({ variant = 'primary', className = '', size = 'md', ...props }) {
    const sizes = { sm: 'h-8 px-2.5 text-xs', md: 'h-10 px-3.5 text-sm' };
    return (
        <button
            type="button"
            {...props}
            className={`inline-flex items-center justify-center gap-1.5 rounded-xl font-semibold transition disabled:cursor-not-allowed disabled:opacity-50 ${sizes[size]} ${variants[variant]} ${className}`}
        />
    );
}

const inputCls =
    'h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-800 outline-none transition placeholder:text-slate-400 focus:border-blue-300 focus:ring-4 focus:ring-blue-500/10';

export function Field({ label, error, hint, children, className = '' }) {
    return (
        <label className={`block ${className}`}>
            {label ? <span className="mb-1 block text-xs font-semibold text-slate-600">{label}</span> : null}
            {children}
            {hint ? <span className="mt-1 block text-[11px] text-slate-400">{hint}</span> : null}
            {error ? <span className="mt-1 block text-xs font-medium text-rose-600">{error}</span> : null}
        </label>
    );
}

export function Input(props) {
    return <input {...props} className={`${inputCls} ${props.className || ''}`} />;
}

export function Select({ children, ...props }) {
    return (
        <select {...props} className={`${inputCls} pr-8 ${props.className || ''}`}>
            {children}
        </select>
    );
}

export function Textarea(props) {
    return <textarea rows={3} {...props} className={`${inputCls} h-auto py-2 ${props.className || ''}`} />;
}

export function EmptyState({ children = 'Aucune donnée' }) {
    return <div className="px-4 py-10 text-center text-sm font-medium text-slate-400">{children}</div>;
}

export function Spinner({ label = 'Chargement…' }) {
    return <div className="px-4 py-10 text-center text-sm font-medium text-slate-500">{label}</div>;
}

export function Alert({ type = 'error', children }) {
    if (!children) return null;
    const cls = type === 'error' ? 'bg-rose-50 text-rose-700 ring-rose-200' : 'bg-emerald-50 text-emerald-700 ring-emerald-200';
    return <div className={`rounded-xl px-3 py-2 text-sm font-medium ring-1 ${cls}`}>{children}</div>;
}

/** Right-side drawer on desktop, bottom sheet on mobile. */
export function Drawer({ open, onClose, title, children, footer, wide = false }) {
    useEffect(() => {
        if (!open) return undefined;
        const onKey = (e) => e.key === 'Escape' && onClose?.();
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [open, onClose]);

    if (!open) return null;
    return (
        <div className="fixed inset-0 z-[60]">
            <button type="button" aria-label="Fermer" className="absolute inset-0 bg-slate-900/50" onClick={onClose} />
            <div
                role="dialog"
                aria-modal="true"
                className={`absolute inset-x-0 bottom-0 flex max-h-[92vh] flex-col rounded-t-2xl bg-white shadow-2xl sm:inset-y-0 sm:left-auto sm:right-0 sm:max-h-none sm:w-full sm:rounded-none ${wide ? 'sm:max-w-2xl' : 'sm:max-w-lg'}`}
            >
                <div className="flex items-center justify-between border-b border-slate-100 px-4 py-3 sm:px-5">
                    <h2 className="text-base font-bold text-slate-900">{title}</h2>
                    <button
                        type="button"
                        onClick={onClose}
                        className="inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100"
                        aria-label="Fermer"
                    >
                        <X className="h-4 w-4" />
                    </button>
                </div>
                <div className="flex-1 overflow-y-auto px-4 py-4 sm:px-5">{children}</div>
                {footer ? <div className="border-t border-slate-100 px-4 py-3 sm:px-5">{footer}</div> : null}
            </div>
        </div>
    );
}

export function Checkbox({ indeterminate = false, ...props }) {
    return (
        <input
            type="checkbox"
            ref={(el) => {
                if (el) el.indeterminate = indeterminate;
            }}
            {...props}
            className={`h-4 w-4 cursor-pointer rounded border-slate-300 text-blue-600 accent-blue-600 ${props.className || ''}`}
        />
    );
}
