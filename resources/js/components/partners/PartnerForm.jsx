import { useEffect, useState } from 'react';
import api, { errorMessage, fieldErrors } from '../../lib/api';
import { Alert, Button, Drawer, Field, Input, Select, Textarea } from '../ui';

export const PARTNER_TYPES = [
    { value: 'ramassage', label: 'Ramassage' },
    { value: 'depot', label: 'Dépôt' },
    { value: 'both', label: 'Les deux' },
];

const empty = (type = 'both', name = '') => ({
    name,
    type,
    phone: '',
    city: '',
    address: '',
    contact_name: '',
    note: '',
    is_active: true,
    is_favorite: false,
});

/** Create / edit a logistics partner (also used for quick creation from the mission drawer). */
export default function PartnerForm({ open, partner, defaultType = 'both', defaultName = '', onClose, onSaved }) {
    const [form, setForm] = useState(empty());
    const [errors, setErrors] = useState({});
    const [error, setError] = useState(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (!open) return;
        setErrors({});
        setError(null);
        setForm(
            partner
                ? {
                      name: partner.name || '',
                      type: partner.type || 'both',
                      phone: partner.phone || '',
                      city: partner.city || '',
                      address: partner.address || '',
                      contact_name: partner.contact_name || '',
                      note: partner.note || '',
                      is_active: !!partner.is_active,
                      is_favorite: !!partner.is_favorite,
                  }
                : empty(defaultType, defaultName),
        );
    }, [open, partner, defaultType, defaultName]);

    const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value }));

    async function submit(e) {
        e?.preventDefault();
        e?.stopPropagation();
        setSaving(true);
        setErrors({});
        setError(null);
        try {
            const { data } = partner?.id ? await api.put(`/logistics-partners/${partner.id}`, form) : await api.post('/logistics-partners', form);
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
            title={partner?.id ? `Modifier ${partner.name}` : 'Ajouter un partenaire'}
            footer={
                <div className="flex justify-end gap-2">
                    <Button variant="secondary" onClick={onClose}>
                        Annuler
                    </Button>
                    <Button onClick={submit} disabled={saving}>
                        {saving ? 'Enregistrement…' : 'Enregistrer'}
                    </Button>
                </div>
            }
        >
            <form onSubmit={submit} className="grid grid-cols-2 gap-3">
                <Field label="Nom du partenaire *" error={errors.name} className="col-span-2">
                    <Input value={form.name} onChange={set('name')} placeholder="Ex : Ozon, Speedaf, Jumia…" required autoFocus />
                </Field>
                <Field
                    label="Type *"
                    error={errors.type}
                    className="col-span-2"
                    hint="« Les deux » : le partenaire apparaît pour les ramassages et les dépôts."
                >
                    <Select value={form.type} onChange={set('type')}>
                        {PARTNER_TYPES.map((t) => (
                            <option key={t.value} value={t.value}>
                                {t.label}
                            </option>
                        ))}
                    </Select>
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
                <Field label="Contact / responsable (optionnel)" error={errors.contact_name} className="col-span-2">
                    <Input value={form.contact_name} onChange={set('contact_name')} />
                </Field>
                <Field label="Note" error={errors.note} className="col-span-2">
                    <Textarea value={form.note} onChange={set('note')} rows={2} placeholder="Horaires, consignes…" />
                </Field>
                <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
                    <input type="checkbox" checked={form.is_favorite} onChange={set('is_favorite')} className="h-4 w-4 accent-blue-600" />
                    Favori (affiché en premier)
                </label>
                <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
                    <input type="checkbox" checked={form.is_active} onChange={set('is_active')} className="h-4 w-4 accent-blue-600" />
                    Actif
                </label>
                <div className="col-span-2">
                    <Alert>{error}</Alert>
                </div>
                <button type="submit" className="hidden" />
            </form>
        </Drawer>
    );
}
