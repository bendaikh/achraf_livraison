import { useState } from 'react';
import { CheckCircle2, ExternalLink, XCircle } from 'lucide-react';
import { Alert, Drawer } from '../ui';
import DocumentLinks from '../ozon/DocumentLinks';

/** « sent envoyée(s) · failed échec(s) », failures first, successes collapsed with their tracking. */
export default function SendResultDrawer({ panel, onClose }) {
    const [openOk, setOpenOk] = useState(false);
    if (!panel) return null;
    const results = panel.results || [];
    const failed = results.filter((r) => !r.success);
    const ok = results.filter((r) => r.success);
    const title = panel.title || 'Résultat';

    return (
        <Drawer open onClose={onClose} title={title}>
            <div className="space-y-3">
                <Alert>{panel.error}</Alert>
                {panel.message ? <Alert type="success">{panel.message}</Alert> : null}
                {results.length ? (
                    <div className="text-sm font-semibold text-slate-800">
                        {ok.length} envoyée(s) · {failed.length} échec(s)
                    </div>
                ) : null}
                {failed.length ? (
                    <ul className="divide-y divide-rose-100 rounded-xl border border-rose-100">
                        {failed.map((r) => (
                            <li key={r.order_id} className="flex items-start gap-2 px-3 py-2 text-sm">
                                <XCircle className="mt-0.5 h-4 w-4 shrink-0 text-rose-600" />
                                <div>
                                    <div className="font-semibold text-slate-800">{r.reference}</div>
                                    <div className="text-xs text-rose-700">{r.message}</div>
                                </div>
                            </li>
                        ))}
                    </ul>
                ) : null}
                {ok.length ? (
                    <div>
                        <button type="button" onClick={() => setOpenOk((v) => !v)} className="text-xs font-semibold text-slate-500 hover:text-slate-800">
                            {openOk ? 'Masquer' : 'Voir'} les {ok.length} envois réussis
                        </button>
                        {openOk ? (
                            <ul className="mt-1 divide-y divide-slate-100 rounded-xl border border-slate-100">
                                {ok.map((r) => (
                                    <li key={r.order_id} className="flex items-start gap-2 px-3 py-2 text-sm">
                                        <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" />
                                        <div>
                                            <div className="font-semibold text-slate-800">{r.reference}</div>
                                            <div className="font-mono text-xs text-slate-500">{r.tracking || r.message}</div>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        ) : null}
                    </div>
                ) : null}
                {panel.labels?.length ? (
                    <ul className="divide-y divide-slate-100 rounded-xl border border-slate-100">
                        {panel.labels.map((l) => (
                            <li key={`${l.carrier || ''}-${l.order_id}`} className="flex items-center justify-between gap-2 px-3 py-2 text-sm">
                                <div>
                                    <div className="font-semibold text-slate-800">{l.reference}</div>
                                    <div className="font-mono text-xs text-slate-500">
                                        {l.carrier_label ? `${l.carrier_label} · ` : ''}
                                        {l.tracking}
                                    </div>
                                </div>
                                {l.pdf_url ? (
                                    <a href={l.pdf_url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-xs font-semibold text-blue-600">
                                        PDF <ExternalLink className="h-3 w-3" />
                                    </a>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                ) : null}
                {panel.notes?.length ? (
                    <ul className="space-y-2">
                        {panel.notes.filter(Boolean).map((n) => (
                            <li key={n.id || n.ref} className="rounded-xl border border-slate-100 px-3 py-2 text-sm">
                                <div className="font-semibold text-slate-800">
                                    BL <span className="font-mono">{n.ref}</span>
                                    {n.parcels_count != null ? ` · ${n.parcels_count} colis` : ''}
                                </div>
                                {n.state === 'saved' ? <div className="mt-1.5"><DocumentLinks documents={n.documents} compact /></div> : <div className="mt-1 text-xs text-amber-700">{n.last_error || 'BL non finalisé.'}</div>}
                            </li>
                        ))}
                    </ul>
                ) : null}
            </div>
        </Drawer>
    );
}
