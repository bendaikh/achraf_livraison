import api from '../../lib/api';

export function apiPath(url) {
    return String(url || '').replace(/^\/api/, '') || '/';
}

export function browserUrl(url) {
    const path = String(url || '');
    if (path.startsWith('http')) return path;
    if (path.startsWith('/api')) return path;
    return `/api${path.startsWith('/') ? '' : '/'}${path}`;
}

/** Runs a carrier action descriptor (method + URL) returned by the server. */
export async function runAction(action, { orderId, orderIds, extra } = {}) {
    if (action.confirm && !window.confirm(action.confirm)) return null;
    const data = { ...(extra || {}) };
    if (action.prompt) {
        const value = window.prompt(action.prompt, action.prompt_default || '');
        if (value === null) return null;
        data.reason = value;
    }
    if (action.body === 'order_ids') data.order_ids = orderIds || [orderId];
    if ((action.method || 'POST').toUpperCase() === 'GET') {
        window.open(browserUrl(action.url), '_blank', 'noopener');
        return { opened: true };
    }
    const res = await api.request({
        method: action.method || 'POST',
        url: apiPath(action.url),
        data: Object.keys(data).length ? data : undefined,
    });
    return res.data;
}
