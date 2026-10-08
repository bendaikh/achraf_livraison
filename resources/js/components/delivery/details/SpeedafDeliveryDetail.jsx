import { formatDateTime } from '../../../lib/format';

/** Extra Speedaf fields (events) under the shared Expédition / Livraison block. */
export default function SpeedafDeliveryDetail({ order }) {
    const shipment = order.speedaf;
    const tracks = (order.speedaf_history || []).find((s) => s.id === shipment?.id)?.tracks || [];
    if (!shipment && tracks.length === 0) return null;
    return (
        <div className="space-y-1 text-xs text-slate-600">
            {shipment?.status_message ? <div>{shipment.status_message}</div> : null}
            {shipment?.last_error ? <div className="font-medium text-rose-700">{shipment.last_error}</div> : null}
            {tracks.length ? (
                <ul className="max-h-28 space-y-0.5 overflow-y-auto">
                    {tracks.slice(0, 6).map((t, i) => (
                        <li key={i}>
                            {t.actionName || t.action || 'Événement'}
                            {t.time ? ` · ${formatDateTime(t.time)}` : ''}
                        </li>
                    ))}
                </ul>
            ) : null}
        </div>
    );
}
