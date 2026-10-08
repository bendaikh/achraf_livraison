/** Pure helpers for the delivery-mode menus (bulk bar, row, fiche commande). */

export const MAX_BULK = 50;

export function chunkIds(ids, size = MAX_BULK) {
    const unique = [];
    const seen = new Set();
    (ids || []).forEach((id) => {
        const n = Number(id);
        if (!seen.has(n)) {
            seen.add(n);
            unique.push(n);
        }
    });
    const chunks = [];
    for (let i = 0; i < unique.length; i += size) chunks.push(unique.slice(i, i + size));
    return chunks;
}

/** Accent-insensitive comparison key. */
export function fold(value) {
    return String(value ?? '')
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase();
}

export function recentStorageKey(userId, companyId) {
    return `lavfast:delivery-recent:${userId || 0}:${companyId || 0}`;
}

export function readRecents(userId, companyId) {
    if (typeof localStorage === 'undefined') return [];
    try {
        const raw = JSON.parse(localStorage.getItem(recentStorageKey(userId, companyId)) || '[]');
        return Array.isArray(raw) ? raw.filter((k) => typeof k === 'string').slice(0, 3) : [];
    } catch {
        return [];
    }
}

export function pushRecent(userId, companyId, key) {
    const next = [key, ...readRecents(userId, companyId).filter((k) => k !== key)].slice(0, 3);
    if (typeof localStorage !== 'undefined') {
        localStorage.setItem(recentStorageKey(userId, companyId), JSON.stringify(next));
    }
    return next;
}

/** Up to 3 carrier keys: this browser first, otherwise the company's recent_rank. */
export function recentCarrierKeys(modes, stored) {
    const available = new Set((modes || []).filter((m) => m.type === 'carrier' && m.available).map((m) => m.key));
    const fromStore = (stored || []).filter((k) => available.has(k)).slice(0, 3);
    if (fromStore.length) return fromStore;
    return (modes || [])
        .filter((m) => m.type === 'carrier' && m.available && m.recent_rank)
        .slice()
        .sort((a, b) => a.recent_rank - b.recent_rank)
        .slice(0, 3)
        .map((m) => m.key);
}

export function byLabel(a, b) {
    return fold(a.label).localeCompare(fold(b.label), 'fr');
}

/** Ids that did not succeed stay selected so they can be fixed and retried. */
export function failedSelection(originalIds, results) {
    const success = new Set((results || []).filter((r) => r.success).map((r) => r.order_id));
    return (originalIds || []).filter((id) => !success.has(Number(id)) && !success.has(id));
}
