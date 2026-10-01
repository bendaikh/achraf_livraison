export function formatDate(value) {
    if (!value) return '—';
    try {
        return new Intl.DateTimeFormat('fr-FR', {
            dateStyle: 'short',
            timeStyle: 'short',
        }).format(new Date(value));
    } catch {
        return value;
    }
}

/** History line: 24/09/2026 21:35 – Pas de réponse – Super Administrateur */
export function formatHistoryDate(value) {
    if (!value) return '—';
    try {
        const d = new Date(value);
        const pad = (n) => String(n).padStart(2, '0');
        return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
    } catch {
        return value;
    }
}

export function formatHistoryLine(entry) {
    if (!entry) return '—';
    const parts = [formatHistoryDate(entry.at), entry.label || entry.type || 'Action'];
    if (entry.user_name) parts.push(entry.user_name);
    if (entry.comment) parts.push(entry.comment);
    return parts.join(' – ');
}

export function formatMoney(amount, currency = 'MAD') {
    if (amount == null || amount === '') return '—';
    const num = Number(amount);
    if (Number.isNaN(num)) return `${amount} ${currency}`;
    return `${num.toLocaleString('fr-FR', { minimumFractionDigits: 0, maximumFractionDigits: 2 })} ${currency}`;
}

export function normalizePhone(phone) {
    if (!phone) return '';
    return String(phone).replace(/[^\d+]/g, '');
}

export function whatsappUrl(phone) {
    const digits = normalizePhone(phone).replace(/^\+/, '');
    if (!digits) return null;
    return `https://wa.me/${digits}`;
}

export function telUrl(phone) {
    const normalized = normalizePhone(phone);
    return normalized ? `tel:${normalized}` : null;
}

function hexToRgba(hex, alpha) {
    const raw = String(hex || '').replace('#', '').trim();
    if (!/^[0-9a-fA-F]{6}$/.test(raw)) {
        return `rgba(100, 116, 139, ${alpha})`;
    }
    const r = parseInt(raw.slice(0, 2), 16);
    const g = parseInt(raw.slice(2, 4), 16);
    const b = parseInt(raw.slice(4, 6), 16);
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

/** Dynamic badge style from status color (DB-driven). */
export function statusBadgeStyle(color) {
    const c = color || '#64748b';
    return {
        backgroundColor: hexToRgba(c, 0.12),
        color: c,
        boxShadow: `inset 0 0 0 1px ${hexToRgba(c, 0.22)}`,
    };
}

export function orderDisplayName(order) {
    if (!order) return '';
    return order.name || (order.order_number ? `FAST${order.order_number}` : '—');
}
