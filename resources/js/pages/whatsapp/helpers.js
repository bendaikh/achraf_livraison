function pad(n) {
    return String(n).padStart(2, '0');
}

export function formatConversationTime(value) {
    if (!value) return '';
    try {
        const d = new Date(value);
        const now = new Date();
        const sameDay =
            d.getFullYear() === now.getFullYear() &&
            d.getMonth() === now.getMonth() &&
            d.getDate() === now.getDate();
        if (sameDay) {
            return `${pad(d.getHours())}:${pad(d.getMinutes())}`;
        }
        return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}`;
    } catch {
        return '';
    }
}

export function formatMessageTime(value) {
    if (!value) return '';
    try {
        const d = new Date(value);
        return `${pad(d.getHours())}:${pad(d.getMinutes())}`;
    } catch {
        return '';
    }
}

export function statusLabel(status) {
    return (
        {
            pending: 'En attente',
            sent: 'Envoyé',
            delivered: 'Délivré',
            read: 'Lu',
            failed: 'Erreur',
            connected: 'Connecté',
            disconnected: 'Déconnecté',
            error: 'Erreur',
            open: 'Ouverte',
            resolved: 'Traitée',
            APPROVED: 'Approuvé',
            PENDING: 'En attente Meta',
            REJECTED: 'Rejeté',
        }[status] || status
    );
}
