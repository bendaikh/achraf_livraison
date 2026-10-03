import { useEffect, useState } from 'react';
import api, { errorMessage, fieldErrors } from '../../lib/api';
import { todayISO } from '../../lib/format';
import { Alert, Button, Drawer, Field, Input, Textarea } from '../ui';

/**
 * Collects the fields a target status requires (Paramètres → Statuts: date+heure de report, motif,
 * montant encaissé…) before a Kanban drop or a bulk status change. Same API validation as manual changes.
 * orderIds.length === 1 → POST /orders/{id}/status, else POST /orders/bulk-status.
 */
export default function StatusMoveDialog({ move, onClose, onDone }) {
    const [form, setForm] = useState({});
    const [errors, setErrors] = useState({});
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (move) {
            setForm({
                reason: '',
                postponed_date: todayISO(1),
                postponed_time: '10:00',
                collected_amount: move.amount ?? '',
                note: '',
            });
            setErrors({});
            setError(null);
        }
    }, [move]);

    if (!move) return null;
    const st = move.status;
    const req = st.required_fields || [];
    const needsAmount = req.includes('collected_amount') || st.category === 'succes';
    const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

    async function submit() {
        setBusy(true);
        setErrors({});
        setError(null);
        const payload = { delivery_status_id: st.id, note: form.note || null };
        if (req.includes('reason') || form.reason) payload.reason = form.reason || null;
        if (req.includes('postponed_at'))
            Object.assign(payload, {
                postponed_date: form.postponed_date,
                postponed_time: form.postponed_time,
            });
        if (needsAmount) payload.collected_amount = form.collected_amount === '' ? null : form.collected_amount;
        try {
            const { data } =
                move.orderIds.length === 1
                    ? await api.post(`/orders/${move.orderIds[0]}/status`, payload)
                    : await api.post('/orders/bulk-status', {
                          ...payload,
                          order_ids: move.orderIds,
                      });
            onDone?.(data);
        } catch (e) {
            setErrors(fieldErrors(e));
            const failed = e.response?.data?.failed;
            setError(failed?.length ? failed.map((f) => `${f.reference} : ${f.message}`).join(' · ') : errorMessage(e));
        } finally {
            setBusy(false);
        }
    }

    return (
        <Drawer
            open
            onClose={onClose}
            title={`Passer en « ${st.name} »`}
            footer={
                <Button className="w-full" onClick={submit} disabled={busy}>
                    {busy ? 'Enregistrement…' : `Valider (${move.orderIds.length} commande${move.orderIds.length > 1 ? 's' : ''})`}
                </Button>
            }
        >
            <div className="space-y-3">
                <p className="text-sm text-slate-500">{move.label || 'Les informations demandées par ce statut sont obligatoires.'}</p>
                <Alert>{error}</Alert>
                {req.includes('postponed_at') ? (
                    <div className="grid grid-cols-2 gap-2">
                        <Field label="Date de report *" error={errors.postponed_date?.[0]}>
                            <Input type="date" value={form.postponed_date} onChange={set('postponed_date')} />
                        </Field>
                        <Field label="Heure *" error={errors.postponed_time?.[0]}>
                            <Input type="time" value={form.postponed_time} onChange={set('postponed_time')} />
                        </Field>
                    </div>
                ) : null}
                {req.includes('reason') ? (
                    <Field label="Motif *" error={errors.reason?.[0]}>
                        <Input value={form.reason} onChange={set('reason')} placeholder="Motif" autoFocus />
                    </Field>
                ) : null}
                {needsAmount ? (
                    <Field
                        label={`Montant encaissé${req.includes('collected_amount') ? ' *' : ''}`}
                        error={errors.collected_amount?.[0]}
                        hint={move.orderIds.length > 1 ? 'Appliqué à chaque commande.' : null}
                    >
                        <Input type="number" min="0" step="0.01" value={form.collected_amount} onChange={set('collected_amount')} />
                    </Field>
                ) : null}
                <Field label={req.includes('note') ? 'Commentaire *' : 'Commentaire'} error={errors.note?.[0]}>
                    <Textarea value={form.note} onChange={set('note')} />
                </Field>
            </div>
        </Drawer>
    );
}
