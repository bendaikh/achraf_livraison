import { useState } from 'react';
import { CheckCircle2, ExternalLink, Printer, Send, XCircle } from 'lucide-react';
import api, { errorMessage } from '../../lib/api';
import useCarriers from '../../hooks/useCarriers';
import { Alert, Button, Drawer } from '../ui';

/**
 * Commandes multi-select → delivery companies (T11): one « Envoyer à X » per registered
 * carrier (POST /api/carriers/{key}/ship) and « Imprimer les étiquettes » for every carrier
 * (POST /api/carriers/labels). Results are listed in a drawer.
 */
export default function CarrierBulkActions({ ids, onDone }) {
    const { carriers, can_ship: canShip } = useCarriers();
    const [busy, setBusy] = useState('');
    const [panel, setPanel] = useState(null);

    async function send(c) {
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
                      <Button key={c.key} size="sm" onClick={() => send(c)} disabled={!!busy || !c.available} title={c.reason || ''}>
                          <Send className="h-3.5 w-3.5" /> {busy === c.key ? 'Envoi…' : `Envoyer à ${c.label}`}
                      </Button>
                  ))
                : null}
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
        </>
    );
}
