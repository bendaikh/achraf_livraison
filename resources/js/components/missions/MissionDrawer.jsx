import { useEffect, useState } from 'react';
import api, { errorMessage, fieldErrors } from '../../lib/api';
import { useMeta } from '../../context/MetaContext';
import { formatDH, todayISO } from '../../lib/format';
import { Alert, Button, Drawer, Field, Input, Select, Textarea } from '../ui';

const CONFIG = {
    ramassage: {
        title: 'Créer un ramassage',
        contact: 'Client / contact *',
        contactPh: 'Nom du client ou de la boutique',
        items: 'Description des articles',
        withCash: true,
    },
    depot_partenaire: {
        title: 'Créer un dépôt partenaire',
        contact: 'Partenaire / destination *',
        contactPh: 'Ex : Agence Ozone, Speedaf…',
        items: 'Articles / colis',
        withCash: false,
    },
};

const empty = () => ({
    contact_name: '',
    phone: '',
    address: '',
    city: '',
    items_description: '',
    quantity: 1,
    scheduled_date: todayISO(),
    time_slot: '',
    driver_id: '',
    note: '',
    cash_amount: '',
    cash_direction: 'collect',
});

/** Drawer creating a Ramassage or Dépôt partenaire mission (price snapshot from the driver's tariff). */
export default function MissionDrawer({ type, open, onClose, onCreated }) {
    const meta = useMeta();
    const cfg = CONFIG[type] || CONFIG.ramassage;
    const [form, setForm] = useState(empty);
    const [errors, setErrors] = useState({});
    const [error, setError] = useState(null);
    const [saving, setSaving] = useState(false);
    const [tariffs, setTariffs] = useState({});

    useEffect(() => {
        if (!open) return;
        setForm(empty());
        setErrors({});
        setError(null);
        api.get('/drivers', { params: { active: 1 } })
            .then(({ data }) => setTariffs(Object.fromEntries(data.data.map((d) => [d.id, d.tariffs]))))
            .catch(() => {});
    }, [open, type]);

    const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));
    const price = form.driver_id ? tariffs[form.driver_id]?.[type] : null;

    async function submit(e) {
        e?.preventDefault();
        setSaving(true);
        setErrors({});
        setError(null);
        try {
            const payload = {
                ...form,
                type,
                driver_id: form.driver_id ? Number(form.driver_id) : null,
                quantity: Number(form.quantity || 1),
                cash_amount: cfg.withCash && form.cash_amount !== '' ? Number(form.cash_amount) : null,
                cash_direction: cfg.withCash && form.cash_amount !== '' ? form.cash_direction : null,
            };
            const { data } = await api.post('/missions', payload);
            onCreated?.(data.data);
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
            title={cfg.title}
            footer={
                <div className="flex items-center justify-between gap-2">
                    <span className="text-xs font-medium text-slate-500">
                        {price !== null && price !== undefined ? `Tarif livreur appliqué : ${formatDH(price)}` : 'Aucun livreur sélectionné'}
                    </span>
                    <div className="flex gap-2">
                        <Button variant="secondary" onClick={onClose}>
                            Annuler
                        </Button>
                        <Button onClick={submit} disabled={saving}>
                            {saving ? 'Création…' : 'Créer la mission'}
                        </Button>
                    </div>
                </div>
            }
        >
            <form onSubmit={submit} className="grid grid-cols-2 gap-3">
                <Field label={cfg.contact} error={errors.contact_name} className="col-span-2">
                    <Input value={form.contact_name} onChange={set('contact_name')} placeholder={cfg.contactPh} required />
                </Field>
                <Field label="Téléphone" error={errors.phone}>
                    <Input value={form.phone} onChange={set('phone')} />
                </Field>
                <Field label="Ville" error={errors.city}>
                    <Input value={form.city} onChange={set('city')} />
                </Field>
                <Field label="Adresse" error={errors.address} className="col-span-2">
                    <Input value={form.address} onChange={set('address')} />
                </Field>
                <Field label={cfg.items} error={errors.items_description} className="col-span-2">
                    <Textarea value={form.items_description} onChange={set('items_description')} rows={2} />
                </Field>
                <Field label="Quantité" error={errors.quantity}>
                    <Input type="number" min="1" value={form.quantity} onChange={set('quantity')} />
                </Field>
                <Field label="Date souhaitée" error={errors.scheduled_date}>
                    <Input type="date" value={form.scheduled_date} onChange={set('scheduled_date')} />
                </Field>
                <Field label="Heure / créneau" error={errors.time_slot}>
                    <Input value={form.time_slot} onChange={set('time_slot')} placeholder="Ex : 10:00 - 12:00" />
                </Field>
                <Field label="Livreur à affecter" error={errors.driver_id}>
                    <Select value={form.driver_id} onChange={set('driver_id')}>
                        <option value="">— Non affecté —</option>
                        {meta.drivers.map((d) => (
                            <option key={d.id} value={d.id}>
                                {d.name}
                            </option>
                        ))}
                    </Select>
                </Field>
                {cfg.withCash ? (
                    <>
                        <Field label="Montant (DH, optionnel)" error={errors.cash_amount}>
                            <Input type="number" min="0" step="0.01" value={form.cash_amount} onChange={set('cash_amount')} />
                        </Field>
                        <Field label="Sens du montant" error={errors.cash_direction}>
                            <Select value={form.cash_direction} onChange={set('cash_direction')}>
                                <option value="collect">À récupérer</option>
                                <option value="remit">À remettre</option>
                            </Select>
                        </Field>
                    </>
                ) : null}
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
