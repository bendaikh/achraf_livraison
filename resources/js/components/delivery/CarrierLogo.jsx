/** Logo from the server, or a coloured chip with the label initials. */
export default function CarrierLogo({ mode, className = 'h-7 w-7' }) {
    const label = mode?.label || '';
    if (mode?.logo) {
        return <img src={mode.logo} alt="" className={`${className} shrink-0 rounded-lg bg-white object-contain`} />;
    }
    const initials = label.replace(/[^A-Za-zÀ-ÿ0-9]/g, '').slice(0, 2).toUpperCase() || '•';
    return (
        <span className={`${className} inline-flex shrink-0 items-center justify-center rounded-lg text-[10px] font-extrabold text-white`} style={{ backgroundColor: mode?.color || '#64748b' }}>
            {initials}
        </span>
    );
}
