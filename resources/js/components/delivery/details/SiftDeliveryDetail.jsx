import { useEffect, useState } from 'react';
import api, { errorMessage } from '../../../lib/api';
import { formatDH } from '../../../lib/format';
import { Button, Field, Input } from '../../ui';

/** Sift parcel details and the edit form opened from the « ⋯ » action (ui: sift-edit). */
export default function SiftDeliveryDetail({ order, onChanged, request, onRequestHandled }) {
    const s = order.sift;
    const [editing, setEditing] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [form, setForm] = useState({});

    useEffect(() => {
        if (request?.ui === 'sift-edit' && s) {
            setForm({
                receiver: s.receiver || '',
                phone: s.phone || '',
                address: s.address || '',
                city: s.city || '',
                cod_amount: s.cod_amount ?? '',
                notes: '',
            });
            setEditing(true);
            onRequestHandled?.();
        }
    }, [request, s, onRequestHandled]);

    if (!s) return null;

    async function save(e) {
        e.preventDefault();
        setBusy(true);
        setError(null);
        const payload = Object.fromEntries(
            Object.entries(form).filter(([k, v]) => String(v) !== String(k === 'cod_amount' ? (s.cod_amount ?? '') : s[k] || '')),
        );
        try {
            const { data } = await api.put(`/sift/orders/${order.id}`, payload);
            if (data.data) onChanged?.(data.data);
            setEditing(false);
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            setBusy(false);
        }
    }

    const set = (k) => (ev) => setForm((f) => ({ ...f, [k]: ev.target.value }));

    return (
        <div className="space-y-1.5 text-xs text-slate-600">
            {s.parcel_id ? <div>parcelId : <span className="font-mono">{s.parcel_id}</span></div> : null}
            {s.city ? <div>Ville : {s.city}</div> : null}
            {s.cod_amount != null ? <div>COD transmis : {formatDH(s.cod_amount)}</div> : null}
            {s.raw_status_comment ? <div>{s.raw_status_comment}</div> : null}
            {s.last_error ? <div className="font-medium text-rose-700">{s.last_error}</div> : null}
            {editing ? (
                <form className="space-y-2 rounded-xl bg-slate-50 p-2" onSubmit={save}>
                    {error ? <div className="font-medium text-rose-700">{error}</div> : null}
                    <div className="grid gap-2 sm:grid-cols-2">
                        <Field label="Nom"><Input value={form.receiver || ''} onChange={set('receiver')} /></Field>
                        <Field label="Téléphone"><Input value={form.phone || ''} onChange={set('phone')} /></Field>
                        <Field label="Ville"><Input value={form.city || ''} onChange={set('city')} /></Field>
                        <Field label="COD (DH)"><Input type="number" min="0" step="0.01" value={form.cod_amount} onChange={set('cod_amount')} /></Field>
                    </div>
                    <Field label="Adresse"><Input value={form.address || ''} onChange={set('address')} /></Field>
                    <div className="flex gap-2">
                        <Button size="sm" type="submit" disabled={busy}>{busy ? 'Envoi…' : 'Enregistrer chez Sift'}</Button>
                        <Button size="sm" variant="secondary" type="button" onClick={() => setEditing(false)}>Fermer</Button>
                    </div>
                </form>
            ) : null}
        </div>
    );
}
