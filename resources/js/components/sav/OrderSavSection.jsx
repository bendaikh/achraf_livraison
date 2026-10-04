import { useCallback, useEffect, useState } from 'react';
import { Plus, Send } from 'lucide-react';
import api from '../../lib/api';
import { formatDateTime, formatDH } from '../../lib/format';
import { Button, Card } from '../ui';
import SavCreateDrawer from './SavCreateDrawer';
import SavDetailDrawer, { SavStatus } from './SavDetailDrawer';
import SavItems from './SavItems';
import SavTimeline from './SavTimeline';
import useCarriers from '../../hooks/useCarriers';
import CarrierSendDialog from '../ozon/CarrierSendDialog';

/** T7 — Fiche commande → « SAV / Retours & échanges » (plusieurs demandes possibles). */
export default function OrderSavSection({ order, onChanged }) {
    const [rows, setRows] = useState(null);
    const [ozonSav, setOzonSav] = useState(null);
    const { carriers } = useCarriers();
    const ozon = carriers.find((c) => c.key === 'ozon' && c.available);
    const ozonFor = (savId) => (order.ozon_shipments || []).find((x) => x.sav_request_id === savId && x.state !== 'cancelled');
    const [creating, setCreating] = useState(false);
    const [openId, setOpenId] = useState(null);
    const [expanded, setExpanded] = useState(null);
    const delivered = order.delivery_status?.category === 'succes';

    const load = useCallback(() => api.get(`/orders/${order.id}/sav`).then(({ data }) => setRows(data.data)), [order.id]);
    useEffect(() => {
        load();
    }, [load]);

    if (!rows || (!rows.length && !delivered)) return null;
    return (
        <Card
            title="SAV / Retours & échanges"
            subtitle={rows.length ? `${rows.length} demande(s)` : 'Aucune demande'}
            actions={
                delivered ? (
                    <Button size="sm" variant="secondary" onClick={() => setCreating(true)}>
                        <Plus className="h-3.5 w-3.5" /> Retour / échange
                    </Button>
                ) : null
            }
            bodyClassName={rows.length ? 'p-3 space-y-3' : 'p-0'}
        >
            {rows.map((s) => (
                <div key={s.id} className="rounded-xl border border-slate-200 p-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <button type="button" onClick={() => setOpenId(s.id)} className="text-sm font-bold text-blue-700 hover:underline">
                            {s.type_label} {s.reference}
                        </button>
                        <div className="flex items-center gap-2">
                            {s.type === 'echange' && ozon && !ozonFor(s.id) && !['cancelled', 'closed'].includes(s.status) ? (
                                <Button size="sm" variant="secondary" onClick={() => setOzonSav(s.id)}>
                                    <Send className="h-3.5 w-3.5" /> Envoyer à Ozon
                                </Button>
                            ) : null}
                            {ozonFor(s.id) ? <span className="rounded-full bg-teal-50 px-2 py-0.5 font-mono text-[11px] font-semibold text-teal-700">Ozon {ozonFor(s.id).tracking_number}</span> : null}
                            <SavStatus sav={s} />
                        </div>
                    </div>
                    <div className="mt-1 text-xs text-slate-500">
                        {s.reason} · Livreur : {s.driver_name || '—'}
                        {s.driver_fee !== null ? ` (${formatDH(s.driver_fee)})` : ''} · créée le {formatDateTime(s.created_at)}
                        {s.picked_up_at ? ` · récupéré le ${formatDateTime(s.picked_up_at)}` : ''}
                        {s.received_at ? ` · reçu au dépôt le ${formatDateTime(s.received_at)}` : ''}
                    </div>
                    <div className="mt-2">
                        <SavItems sav={s} />
                    </div>
                    <button type="button" className="mt-1 text-xs font-semibold text-slate-500 hover:text-slate-800" onClick={() => setExpanded(expanded === s.id ? null : s.id)}>
                        {expanded === s.id ? 'Masquer l’historique' : 'Voir l’historique'}
                    </button>
                    {expanded === s.id ? (
                        <div className="mt-2">
                            <SavTimeline history={s.history} />
                        </div>
                    ) : null}
                </div>
            ))}
            <SavCreateDrawer
                open={creating}
                initialOrderId={order.id}
                onClose={() => setCreating(false)}
                onCreated={() => {
                    setCreating(false);
                    load();
                }}
            />
            {ozonSav && ozon ? (
                <CarrierSendDialog
                    carrier={ozon}
                    savId={ozonSav}
                    onClose={() => setOzonSav(null)}
                    onDone={() => {
                        load();
                        onChanged?.();
                    }}
                />
            ) : null}
            <SavDetailDrawer id={openId} onClose={() => setOpenId(null)} onChanged={load} />
        </Card>
    );
}
