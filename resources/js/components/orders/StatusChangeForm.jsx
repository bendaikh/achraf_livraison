import { useState } from 'react';
import { useMeta } from '../../context/MetaContext';
import api, { errorMessage, fieldErrors } from '../../lib/api';
import { todayISO } from '../../lib/format';
import { Alert, Button, Field, Input, Select, Textarea } from '../ui';

/**
 * Change an order's delivery status. The list of statuses and the fields each status
 * requires both come from the API (Paramètres → Statuts de livraison).
 */
export default function StatusChangeForm({ order, onChanged }) {
    const { statuses } = useMeta();
    const [statusId, setStatusId] = useState('');
    const [form, setForm] = useState({ reason: '', postponed_date: todayISO(1), postponed_time: '10:00', collected_amount: '', note: '' });
    const [errors, setErrors] = useState({});
    const [error, setError] = useState(null);
    const [saving, setSaving] = useState(false);

    const allowedIds = order.allowed_status_ids; // null = every active status allowed
    const options = statuses.filter((s) => s.id !== order.delivery_status_id && (!allowedIds || allowedIds.includes(s.id)));
    const selected = statuses.find((s) => String(s.id) === String(statusId));
    const required = selected?.required_fields || [];
    const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

    async function submit(e) {
        e.preventDefault();
        if (!selected) return;
        setSaving(true);
        setError(null);
        setErrors({});
        try {
            const payload = { delivery_status_id: selected.id, note: form.note || null };
            if (required.includes('reason') || form.reason) payload.reason = form.reason || null;
            if (required.includes('postponed_at')) {
                payload.postponed_date = form.postponed_date || null;
                payload.postponed_time = form.postponed_time || null;
            }
            if (required.includes('collected_amount') || selected.category === 'succes') {
                payload.collected_amount = form.collected_amount === '' ? null : form.collected_amount;
            }
            const { data } = await api.post(`/orders/${order.id}/status`, payload);
            setStatusId('');
            setForm((f) => ({ ...f, reason: '', note: '', collected_amount: '' }));
            onChanged?.(data.data);
        } catch (err) {
            setErrors(fieldErrors(err));
            setError(errorMessage(err));
        } finally {
            setSaving(false);
        }
    }

    return (
        <form onSubmit={submit} className="space-y-3">
            <Field label="Nouveau statut de livraison">
                <Select value={statusId} onChange={(e) => setStatusId(e.target.value)} required>
                    <option value="">Choisir un statut…</option>
                    {options.map((s) => (
                        <option key={s.id} value={s.id}>
                            {s.name}
                        </option>
                    ))}
                </Select>
            </Field>

            {required.includes('postponed_at') ? (
                <div className="grid grid-cols-2 gap-3">
                    <Field label="Date de report *" error={errors.postponed_date}>
                        <Input type="date" value={form.postponed_date} onChange={set('postponed_date')} />
                    </Field>
                    <Field label="Heure *" error={errors.postponed_time}>
                        <Input type="time" value={form.postponed_time} onChange={set('postponed_time')} />
                    </Field>
                </div>
            ) : null}
            {required.includes('reason') ? (
                <Field label="Motif *" error={errors.reason}>
                    <Input value={form.reason} onChange={set('reason')} placeholder="Ex : client absent, adresse erronée…" />
                </Field>
            ) : null}
            {required.includes('collected_amount') || selected?.category === 'succes' ? (
                <Field
                    label={`Montant encaissé (DH)${required.includes('collected_amount') ? ' *' : ''}`}
                    error={errors.collected_amount}
                    hint={`Montant de la commande : ${order.amount} DH`}
                >
                    <Input type="number" min="0" step="0.01" value={form.collected_amount} onChange={set('collected_amount')} />
                </Field>
            ) : null}
            {selected ? (
                <Field label={`Commentaire${required.includes('note') ? ' *' : ''}`} error={errors.note}>
                    <Textarea value={form.note} onChange={set('note')} rows={2} />
                </Field>
            ) : null}

            <Alert>{error}</Alert>
            <Button type="submit" disabled={!selected || saving} className="w-full">
                {saving ? 'Enregistrement…' : 'Changer le statut'}
            </Button>
        </form>
    );
}
