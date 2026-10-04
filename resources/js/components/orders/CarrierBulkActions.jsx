import { useState } from 'react';
import { CheckCircle2, ExternalLink, FileText, Printer, Send, Tag, XCircle } from 'lucide-react';
import api, { errorMessage } from '../../lib/api';
import useCarriers from '../../hooks/useCarriers';
import { Alert, Button, Drawer } from '../ui';
import CarrierSendDialog from '../ozon/CarrierSendDialog';
import DocumentLinks from '../ozon/DocumentLinks';

/**
 * Commandes multi-select → delivery companies (T11): one « Envoyer à X » per registered
 * carrier (POST /api/carriers/{key}/ship) and « Imprimer les étiquettes » for every carrier
 * (POST /api/carriers/labels). Results are listed in a drawer.
 */
export default function CarrierBulkActions({ ids, onDone }) {
    const { carriers, can_ship: canShip } = useCarriers();
    const [busy, setBusy] = useState('');
    const [panel, setPanel] = useState(null);
    const [dialog, setDialog] = useState(null);

    /** Multi-order actions of carriers that keep bulk disabled (Ozon until the test cycle is validated). */
    const bulkOff = (c) => ids.length > 1 && c.bulk_enabled === false;

    async function deliveryNote(c) {
        if (!window.confirm(`Créer un BL ${c.label} avec ${ids.length} commande(s) ? Toutes doivent avoir un suivi ${c.label}.`)) return;
        setBusy(`bl-${c.key}`);
        try {
            const { data } = await api.post('/ozon/delivery-notes', { order_ids: ids });
            setPanel({ type: 'notes', title: `BL ${c.label}`, message: data.message, notes: [data.delivery_note] });
        } catch (e) {
            setPanel({ type: 'notes', title: `BL ${c.label}`, error: errorMessage(e), notes: [] });
        } finally {
            setBusy('');
            onDone?.();
        }
    }

    async function carrierLabels(c) {
        setBusy(`lab-${c.key}`);
        try {
            const { data } = await api.post('/ozon/labels', { order_ids: ids });
            setPanel({ type: 'notes', title: `Étiquettes ${c.label}`, message: data.message, notes: data.delivery_notes });
        } catch (e) {
            setPanel({ type: 'notes', title: `Étiquettes ${c.label}`, error: errorMessage(e), notes: [] });
        } finally {
            setBusy('');
        }
    }

    async function send(c) {
        if (c.preview) {
            setDialog(c);
            return;
        }
        if (!window.confirm(`Envoyer ${ids.length} commande(s) à ${c.label} ?`)) return;
        setBusy(c.key);
        try {
            const { data } = await api.post(`/carriers/${c.key}/ship`, { order_ids: ids });
            setPanel({ type: 'send', title: `Envoi à ${c.label}`, message: data.message, results: data.results });
        } catch (e) {
            setPanel({ type: 'send', title: `Envoi à ${c.label}`, error: errorMessage(e), results: e?.response?.data?.results || [] });
        } finally {
            setBusy('');
            onDone?.();
        }
    }

    async function labels() {
        setBusy('labels');
        try {
            const { data } = await api.post('/carriers/labels', { order_ids: ids });
            if (data.labels?.length === 1) window.open(data.labels[0].pdf_url, '_blank', 'noopener');
            setPanel({ type: 'labels', title: 'Étiquettes', message: data.message, labels: data.labels });
        } catch (e) {
            setPanel({ type: 'labels', title: 'Étiquettes', error: errorMessage(e), labels: [] });
        } finally {
            setBusy('');
        }
    }

    return (
        <>
            {canShip
                ? carriers.map((c) => (
                      <Button key={c.key} size="sm" onClick={() => send(c)} disabled={!!busy || !c.available || bulkOff(c)} title={bulkOff(c) ? c.bulk_reason || '' : c.reason || ''}>
                          <Send className="h-3.5 w-3.5" /> {busy === c.key ? 'Envoi…' : `Envoyer à ${c.label}`}
                      </Button>
                  ))
                : null}
            {canShip
                ? carriers
                      .filter((c) => c.delivery_notes)
                      .map((c) => (
                          <Button key={`bl-${c.key}`} size="sm" variant="secondary" onClick={() => deliveryNote(c)} disabled={!!busy || !c.available || bulkOff(c)} title={bulkOff(c) ? c.bulk_reason || '' : c.reason || ''}>
                              <FileText className="h-3.5 w-3.5" /> {busy === `bl-${c.key}` ? 'Création…' : `Créer BL ${c.label.split(' ')[0]}`}
                          </Button>
                      ))
                : null}
            {carriers
                .filter((c) => c.delivery_notes)
                .map((c) => (
                    <Button key={`lab-${c.key}`} size="sm" variant="secondary" onClick={() => carrierLabels(c)} disabled={!!busy || bulkOff(c)} title={bulkOff(c) ? c.bulk_reason || '' : ''}>
                        <Tag className="h-3.5 w-3.5" /> {busy === `lab-${c.key}` ? 'Préparation…' : `Étiquettes ${c.label.split(' ')[0]}`}
                    </Button>
                ))}
            <Button size="sm" variant="secondary" onClick={labels} disabled={!!busy}>
                <Printer className="h-3.5 w-3.5" /> {busy === 'labels' ? 'Préparation…' : 'Imprimer les étiquettes'}
            </Button>

            <Drawer open={!!panel} onClose={() => setPanel(null)} title={panel?.title || ''}>
                {panel ? (
                    <div className="space-y-3">
                        <Alert>{panel.error}</Alert>
                        {panel.message ? <Alert type="success">{panel.message}</Alert> : null}
                        {panel.type === 'send' && panel.results?.length ? (
                            <ul className="divide-y divide-slate-100 rounded-xl border border-slate-100">
                                {panel.results.map((r) => (
                                    <li key={r.order_id} className="flex items-start gap-2 px-3 py-2 text-sm">
                                        {r.success ? <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /> : <XCircle className="mt-0.5 h-4 w-4 shrink-0 text-rose-600" />}
                                        <div className="min-w-0">
                                            <div className="font-semibold text-slate-800">{r.reference}</div>
                                            <div className={`text-xs ${r.success ? 'text-slate-500' : 'text-rose-700'}`}>{r.message}</div>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        ) : null}
                        {panel.type === 'notes' && panel.notes?.length ? (
                            <ul className="space-y-2">
                                {panel.notes.map((n) => (
                                    <li key={n.id} className="rounded-xl border border-slate-100 px-3 py-2 text-sm">
                                        <div className="font-semibold text-slate-800">
                                            BL <span className="font-mono">{n.ref}</span> · {n.parcels_count} colis
                                        </div>
                                        {n.orders?.length ? <div className="text-xs text-slate-500">{n.orders.join(', ')}</div> : null}
                                        {n.items?.length ? <div className="text-xs text-slate-500">{n.items.map((i) => i.reference).join(', ')}</div> : null}
                                        {n.state === 'saved' ? (
                                            <div className="mt-1.5">
                                                <DocumentLinks documents={n.documents} compact />
                                            </div>
                                        ) : (
                                            <div className="mt-1 text-xs text-amber-700">BL non finalisé : {n.last_error || 'à reprendre depuis Intégrations → Ozon Express.'}</div>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        ) : null}
                        {panel.type === 'labels' && panel.labels?.length ? (
                            <>
                                <ul className="divide-y divide-slate-100 rounded-xl border border-slate-100">
                                    {panel.labels.map((l) => (
                                        <li key={`${l.carrier}-${l.order_id}`} className="flex items-center justify-between gap-2 px-3 py-2 text-sm">
                                            <div>
                                                <div className="font-semibold text-slate-800">{l.reference}</div>
                                                <div className="font-mono text-xs text-slate-500">
                                                    {l.carrier_label} · {l.tracking}
                                                </div>
                                            </div>
                                            <a href={l.pdf_url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-xs font-semibold text-blue-600 hover:text-blue-700">
                                                <ExternalLink className="h-3.5 w-3.5" /> Ouvrir le PDF
                                            </a>
                                        </li>
                                    ))}
                                </ul>
                                <p className="text-xs text-slate-400">Un PDF par colis : ouvrez chaque étiquette pour l’imprimer.</p>
                            </>
                        ) : null}
                    </div>
                ) : null}
            </Drawer>
            {dialog ? <CarrierSendDialog carrier={dialog} orderIds={ids} onClose={() => setDialog(null)} onDone={() => onDone?.()} /> : null}
        </>
    );
}
