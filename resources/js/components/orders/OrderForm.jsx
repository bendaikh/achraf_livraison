import { useEffect, useState } from 'react';
import api, { errorMessage, fieldErrors } from '../../lib/api';
import { useMeta } from '../../context/MetaContext';
import { Alert, Button, Drawer, Field, Input, Select, Textarea } from '../ui';

const EMPTY = {
    customer_name: '',
    customer_phone: '',
    city: '',
    address: '',
    product_name: '',
    product_image: '',
    quantity: 1,
    amount: '',
    payment_method: 'cod',
    source: 'Manuel',
    assigned_user_id: '',
    note: '',
};

export default function OrderForm({ open, onClose, onSaved }) {
    const meta = useMeta();
    const [form, setForm] = useState(EMPTY);
    const [errors, setErrors] = useState({});
    const [error, setError] = useState(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (open) {
            setForm({ ...EMPTY, assigned_user_id: meta.currentUser?.id || '' });
            setErrors({});
            setError(null);
        }
    }, [open, meta.currentUser]);

    const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

    async function submit(e) {
        e?.preventDefault();
        setSaving(true);
        setError(null);
        try {
            const payload = { ...form, assigned_user_id: form.assigned_user_id || null, product_image: form.product_image || null };
            const { data } = await api.post('/orders', payload);
            onSaved?.(data.data);
        } catch (err) {
            setErrors(fieldErrors(err));
            setError(errorMessage(err));
        } finally {
            setSaving(false);
        }
    }

    return (
        <Drawer
            open={open}
            onClose={onClose}
            title="Nouvelle commande"
            footer={
                <div className="flex justify-end gap-2">
                    <Button variant="secondary" onClick={onClose}>
                        Annuler
                    </Button>
                    <Button onClick={submit} disabled={saving}>
                        {saving ? 'Enregistrement…' : 'Créer la commande'}
                    </Button>
                </div>
            }
        >
            <form onSubmit={submit} className="grid grid-cols-2 gap-3">
                <Field label="Client *" error={errors.customer_name} className="col-span-2">
                    <Input value={form.customer_name} onChange={set('customer_name')} required />
                </Field>
                <Field label="Téléphone" error={errors.customer_phone}>
                    <Input value={form.customer_phone} onChange={set('customer_phone')} />
                </Field>
                <Field label="Ville" error={errors.city}>
                    <Input value={form.city} onChange={set('city')} />
                </Field>
                <Field label="Adresse" error={errors.address} className="col-span-2">
                    <Input value={form.address} onChange={set('address')} />
                </Field>
                <Field label="Produit" error={errors.product_name}>
                    <Input value={form.product_name} onChange={set('product_name')} />
                </Field>
                <Field label="Quantité" error={errors.quantity}>
                    <Input type="number" min="1" value={form.quantity} onChange={set('quantity')} />
                </Field>
                <Field label="Image du produit (URL)" error={errors.product_image} className="col-span-2">
                    <Input value={form.product_image} onChange={set('product_image')} placeholder="https://…" />
                </Field>
                <Field label="Montant (DH) *" error={errors.amount}>
                    <Input type="number" min="0" step="0.01" value={form.amount} onChange={set('amount')} required />
                </Field>
                <Field label="Paiement" error={errors.payment_method}>
                    <Select value={form.payment_method} onChange={set('payment_method')}>
                        {meta.paymentMethods.map((p) => (
                            <option key={p.value} value={p.value}>
                                {p.label}
                            </option>
                        ))}
                    </Select>
                </Field>
                <Field label="Source" error={errors.source}>
                    <Input value={form.source} onChange={set('source')} placeholder="Site web, WhatsApp…" />
                </Field>
                <Field label="Utilisateur assigné" error={errors.assigned_user_id}>
                    <Select value={form.assigned_user_id} onChange={set('assigned_user_id')}>
                        <option value="">—</option>
                        {meta.users.map((u) => (
                            <option key={u.id} value={u.id}>
                                {u.name}
                            </option>
                        ))}
                    </Select>
                </Field>
                <Field label="Note" error={errors.note} className="col-span-2">
                    <Textarea value={form.note} onChange={set('note')} rows={2} />
                </Field>
                <div className="col-span-2">
                    <Alert>{error}</Alert>
                </div>
                <button type="submit" className="hidden" />
            </form>
        </Drawer>
    );
}
