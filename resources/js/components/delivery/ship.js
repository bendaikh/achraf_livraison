import api, { errorMessage } from '../../lib/api';
import { chunkIds } from '../../lib/delivery';

/** Local pre-check, split so each request stays within the server limit. No carrier HTTP. */
export async function checkCarrier(key, ids) {
    const rows = [];
    let unavailable = null;
    let bulkBlocked = null;
    for (const orderIds of chunkIds(ids)) {
        const { data } = await api.post(`/delivery-modes/${key}/check`, { order_ids: orderIds });
        rows.push(...(data.rows || []));
        unavailable = unavailable || data.unavailable;
        bulkBlocked = bulkBlocked || data.bulk_blocked;
    }
    const ready = rows.filter((r) => r.can_send).length;
    return { selected: rows.length, ready, problems: rows.length - ready, rows, unavailable, bulkBlocked };
}

/**
 * POST /api/carriers/{key}/ship in chunks of 50. One chunk failing does not stop the next.
 * @returns {Promise<Array>} per-order results
 */
export async function shipCarrier(key, ids, { options, onProgress } = {}) {
    const chunks = chunkIds(ids);
    const total = chunks.reduce((n, c) => n + c.length, 0);
    const results = [];
    let done = 0;
    for (const orderIds of chunks) {
        onProgress?.(done, total);
        try {
            const { data } = await api.post(`/carriers/${key}/ship`, { order_ids: orderIds, ...(options ? { options } : {}) });
            results.push(...(data.results || []));
        } catch (e) {
            const partial = e?.response?.data?.results;
            if (partial?.length) {
                results.push(...partial);
            } else {
                const message = errorMessage(e);
                orderIds.forEach((id) => results.push({ order_id: id, reference: `#${id}`, success: false, tracking: null, message }));
            }
        }
        done += orderIds.length;
        onProgress?.(done, total);
    }
    return results;
}
