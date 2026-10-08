import { useState } from 'react';
import api, { errorMessage } from '../../lib/api';
import { formatDH } from '../../lib/format';
import { useAuth } from '../../contexts/AuthContext';
import { Alert, Button, Field, Select, Textarea } from '../ui';

const MOTIFS = [
    ['client', 'Client a annulé'],
    ['doublon', 'Doublon'],
    ['saisie', 'Erreur de saisie'],
    ['stock', 'Rupture de stock'],
    ['autre', 'Autre'],
];

export function CancelOrderDialog({ orders, onClose, onDone }) {
    const { user, can } = useAuth();
    const [reason, setReason] = useState('client');
    const [comment, setComment] = useState('');
    const [refund, setRefund] = useState(false);
    const [confirmRefund, setConfirmRefund] = useState(false);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);
    const list = Array.isArray(orders) ? orders : [orders];
    const paid = list.some((o) => o && (o.payment_method === 'paye' || o.payment_method === 'partial' || Number(o.amount_paid) > 0));
    const admin = user?.role === 'admin' || user?.role === 'superadmin';
    const amount = list.reduce((sum, o) => sum + Number(o?.amount_paid || 0), 0);

    async function submit() {
        if (refund && !confirmRefund) {
            setConfirmRefund(true);
            return;
        }
        setBusy(true);
        setError(null);
        try {
            const payload = { reason, comment, refund: refund && confirmRefund };
            if (list.length === 1) {
                const { data } = await api.post(`/orders/${list[0].id}/cancel`, payload);
                onDone?.({ single: data });
            } else {
                const { data } = await api.post('/orders/cancel', { ...payload, order_ids: list.map((o) => o.id) });
                onDone?.({ results: data.results || [] });
            }
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy(false);
        }
    }

    return (
        <div className="fixed inset-0 z-[70] flex items-end justify-center sm:items-center">
            <button type="button" className="absolute inset-0 bg-slate-900/50" aria-label="Fermer" onClick={onClose} />
            <div role="dialog" aria-modal="true" className="relative z-10 w-full max-w-lg rounded-t-2xl bg-white p-5 shadow-2xl sm:rounded-2xl">
                <h2 className="text-base font-bold text-slate-900">Annuler la commande</h2>
                <p className="mt-1 text-sm text-slate-500">{list.length > 1 ? `${list.length} commandes. Chaque commande est traitée séparément.` : list[0]?.reference}</p>
                <div className="mt-4 space-y-3">
                    <Alert>{error}</Alert>
                    <Field label="Motif">
                        <Select value={reason} onChange={(e) => setReason(e.target.value)} aria-label="Motif">
                            {MOTIFS.map(([value, label]) => (
                                <option key={value} value={value}>{label}</option>
                            ))}
                        </Select>
                    </Field>
                    <Field label="Commentaire">
                        <Textarea value={comment} onChange={(e) => setComment(e.target.value)} rows={3} />
                    </Field>
                    {paid && admin && can('orders.cancel') ? (
                        <label className="flex items-start gap-2 text-sm text-slate-700">
                            <input type="checkbox" className="mt-1" checked={refund} onChange={(e) => { setRefund(e.target.checked); setConfirmRefund(false); }} />
                            <span>Rembourser le client sur Shopify</span>
                        </label>
                    ) : null}
                    {refund && confirmRefund ? (
                        <p className="rounded-xl bg-rose-50 px-3 py-2 text-sm font-medium text-rose-800">
                            Confirmer le remboursement de {formatDH(amount)} sur Shopify ? Cette action est irréversible.
                        </p>
                    ) : null}
                </div>
                <div className="mt-4 flex justify-end gap-2">
                    <Button variant="secondary" onClick={onClose}>Annuler</Button>
                    <Button onClick={submit} disabled={busy || !reason}>{busy ? 'Annulation…' : confirmRefund && refund ? 'Confirmer le remboursement' : 'Annuler la commande'}</Button>
                </div>
            </div>
        </div>
    );
}

export function DeleteDraftDialog({ orders, onClose, onDone }) {
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);
    const list = Array.isArray(orders) ? orders : [orders];

    async function submit() {
        setBusy(true);
        setError(null);
        try {
            if (list.length === 1) {
                await api.delete(`/orders/${list[0].id}/draft`, { data: { reason: 'Suppression du brouillon' } });
                onDone?.({ deleted: true });
            } else {
                const { data } = await api.post('/orders/delete-drafts', { order_ids: list.map((o) => o.id), reason: 'Suppression du brouillon' });
                onDone?.({ results: data.results || [] });
            }
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy(false);
        }
    }

    return (
        <div className="fixed inset-0 z-[70] flex items-end justify-center sm:items-center">
            <button type="button" className="absolute inset-0 bg-slate-900/50" aria-label="Fermer" onClick={onClose} />
            <div role="dialog" aria-modal="true" className="relative z-10 w-full max-w-md rounded-t-2xl bg-white p-5 shadow-2xl sm:rounded-2xl">
                <h2 className="text-base font-bold text-slate-900">Supprimer le brouillon</h2>
                <p className="mt-2 text-sm text-slate-600">Supprimer définitivement ce brouillon ?</p>
                <Alert>{error}</Alert>
                <div className="mt-4 flex justify-end gap-2">
                    <Button variant="secondary" onClick={onClose}>Annuler</Button>
                    <Button variant="danger" onClick={submit} disabled={busy}>{busy ? 'Suppression…' : 'Supprimer le brouillon'}</Button>
                </div>
            </div>
        </div>
    );
}

export function LifecycleResults({ title, results, onClose }) {
    if (!results) return null;
    return (
        <div className="fixed inset-0 z-[70] flex items-end justify-center sm:items-center">
            <button type="button" className="absolute inset-0 bg-slate-900/50" aria-label="Fermer" onClick={onClose} />
            <div role="dialog" className="relative z-10 max-h-[80vh] w-full max-w-lg overflow-y-auto rounded-t-2xl bg-white p-5 shadow-2xl sm:rounded-2xl">
                <h2 className="text-base font-bold text-slate-900">{title}</h2>
                <ul className="mt-3 divide-y divide-slate-100">
                    {results.map((row) => (
                        <li key={row.order_id} className="py-2 text-sm">
                            <span className="font-semibold text-slate-800">{row.reference || `#${row.order_id}`}</span>
                            <span className={row.ok ? 'text-emerald-700' : 'text-rose-700'}> — {row.message}</span>
                        </li>
                    ))}
                </ul>
                <div className="mt-4 flex justify-end">
                    <Button onClick={onClose}>Fermer</Button>
                </div>
            </div>
        </div>
    );
}
