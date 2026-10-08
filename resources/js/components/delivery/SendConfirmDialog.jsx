import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { Loader2, Send, X } from 'lucide-react';
import { formatDH } from '../../lib/format';
import { checkCarrier, shipCarrier } from './ship';
import { errorMessage } from '../../lib/api';
import { Button } from '../ui';

/**
 * Pre-check then send for carriers without their own preview dialog.
 * Header: « n sélectionnées · ok prêtes · ko avec problème ».
 */
export default function SendConfirmDialog({ carrier, orderIds, onClose, onDone }) {
    const [check, setCheck] = useState(null);
    const [error, setError] = useState(null);
    const [progress, setProgress] = useState(null);
    const [busy, setBusy] = useState(false);
    const [showReady, setShowReady] = useState(false);

    useEffect(() => {
        let alive = true;
        checkCarrier(carrier.key, orderIds)
            .then((data) => {
                if (alive) setCheck(data);
            })
            .catch((e) => {
                if (alive) setError(errorMessage(e));
            });
        return () => {
            alive = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [carrier.key, orderIds.join(',')]);

    useEffect(() => {
        const onKey = (e) => e.key === 'Escape' && !busy && onClose?.();
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [onClose, busy]);

    const rows = check?.rows || [];
    const readyRows = rows.filter((r) => r.can_send);
    const problemRows = rows.filter((r) => !r.can_send);
    const blocked = check?.unavailable || (orderIds.length > 1 ? check?.bulkBlocked : null);

    async function send() {
        setBusy(true);
        setError(null);
        try {
            const results = await shipCarrier(
                carrier.key,
                readyRows.map((r) => r.order_id),
                { onProgress: (done, total) => setProgress({ done, total }) },
            );
            onDone?.(results);
        } catch (e) {
            setError(errorMessage(e));
            setBusy(false);
        }
    }

    const body = (
        <div className="fixed inset-0 z-[60] flex items-end justify-center sm:items-center" onClick={(e) => e.stopPropagation()}>
            <div className="absolute inset-0 bg-slate-900/40" onClick={() => !busy && onClose?.()} />
            <div role="dialog" aria-modal="true" aria-label={`Envoyer à ${carrier.label}`} className="relative flex max-h-[92vh] w-full max-w-lg flex-col rounded-t-2xl bg-white shadow-2xl sm:rounded-2xl">
                <div className="flex items-start justify-between gap-3 border-b border-slate-100 px-4 py-3">
                    <div>
                        <div className="text-sm font-bold text-slate-900">Envoyer à {carrier.label}</div>
                        <div className="text-xs font-semibold text-slate-600">
                            {check ? `${check.selected} sélectionnées · ${check.ready} prêtes · ${check.problems} avec problème` : 'Vérification…'}
                        </div>
                    </div>
                    <button type="button" onClick={onClose} disabled={busy} className="rounded-md p-1 text-slate-400 hover:bg-slate-100" aria-label="Fermer">
                        <X className="h-5 w-5" />
                    </button>
                </div>
                <div className="flex-1 space-y-2 overflow-y-auto px-4 py-3">
                    {error ? <div className="rounded-lg bg-rose-50 px-3 py-2 text-sm font-medium text-rose-700">{error}</div> : null}
                    {blocked ? <div className="rounded-lg bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800">{blocked}</div> : null}
                    {progress ? <div className="text-xs font-semibold text-blue-700">Envoi {progress.done}/{progress.total}…</div> : null}
                    {!check && !error ? <div className="text-sm text-slate-500">Vérification des commandes…</div> : null}
                    {problemRows.length ? (
                        <ul className="divide-y divide-rose-100 rounded-xl border border-rose-100">
                            {problemRows.map((r) => (
                                <li key={r.order_id} className="px-3 py-2 text-sm">
                                    <div className="flex items-start justify-between gap-2">
                                        <span className="font-semibold text-slate-900">{r.reference}</span>
                                        <span className="shrink-0 text-xs text-slate-500">À encaisser : {formatDH(r.amount_due)}</span>
                                    </div>
                                    <div className="text-xs font-medium text-rose-700">{r.reason || r.errors?.join(' · ')}</div>
                                </li>
                            ))}
                        </ul>
                    ) : null}
                    {readyRows.length ? (
                        <div>
                            <button type="button" onClick={() => setShowReady((v) => !v)} className="text-xs font-semibold text-slate-500 hover:text-slate-800">
                                {showReady ? 'Masquer' : 'Voir'} les {readyRows.length} commandes prêtes
                            </button>
                            {showReady ? (
                                <ul className="mt-1 divide-y divide-slate-100 rounded-xl border border-slate-100">
                                    {readyRows.map((r) => (
                                        <li key={r.order_id} className="flex items-center justify-between gap-2 px-3 py-1.5 text-sm">
                                            <span className="font-semibold text-slate-800">{r.reference}</span>
                                            <span className="text-xs text-slate-500">À encaisser : {formatDH(r.amount_due)}</span>
                                        </li>
                                    ))}
                                </ul>
                            ) : null}
                        </div>
                    ) : null}
                </div>
                <div className="flex justify-end gap-2 border-t border-slate-100 px-4 py-3">
                    <Button variant="secondary" onClick={onClose} disabled={busy}>
                        Annuler
                    </Button>
                    <Button onClick={send} disabled={busy || !readyRows.length || !!blocked}>
                        {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                        Envoyer les {readyRows.length} commandes prêtes
                    </Button>
                </div>
            </div>
        </div>
    );

    return createPortal(body, document.body);
}
