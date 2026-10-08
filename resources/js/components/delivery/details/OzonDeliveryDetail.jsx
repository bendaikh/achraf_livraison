import { formatDH } from '../../../lib/format';
import DocumentLinks from '../../ozon/DocumentLinks';

/** Ozon parcel details (ville, BL documents) under the shared block. */
export default function OzonDeliveryDetail({ order }) {
    const s = order.ozon;
    if (!s) return null;
    return (
        <div className="space-y-1.5 text-xs text-slate-600">
            {s.city_name ? <div>Ville : {s.city_name}{s.city_id ? ` (#${s.city_id})` : ''}</div> : null}
            {s.price != null ? <div>COD transmis : {formatDH(s.price)}</div> : null}
            {s.raw_status_comment ? <div>{s.raw_status_comment}</div> : null}
            {s.last_error ? <div className="font-medium text-rose-700">{s.last_error}</div> : null}
            {s.delivery_note ? (
                <div className="rounded-lg bg-slate-50 px-2 py-1.5">
                    <div className="font-semibold text-slate-700">
                        BL <span className="font-mono">{s.delivery_note.ref}</span> · {s.delivery_note.state === 'saved' ? 'enregistré' : 'non finalisé'}
                    </div>
                    {s.delivery_note.state === 'saved' ? <DocumentLinks documents={s.delivery_note.documents} compact /> : null}
                </div>
            ) : null}
        </div>
    );
}
