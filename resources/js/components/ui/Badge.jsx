import StatusIcon from './StatusIcon';

export function ColorBadge({ color = '#64748b', label, icon, className = '' }) {
    return (
        <span
            className={`inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset ${className}`}
            style={{ backgroundColor: `${color}1a`, color, '--tw-ring-color': `${color}33` }}
        >
            {icon ? <StatusIcon name={icon} /> : null}
            {label}
        </span>
    );
}

export function StatusBadge({ status }) {
    if (!status) return <span className="text-xs font-medium text-slate-400">—</span>;
    return (
        <ColorBadge
            color={status.color}
            label={status.name ?? status.label}
            icon={status.icon}
            className={status.is_active === false ? 'opacity-70' : ''}
        />
    );
}
