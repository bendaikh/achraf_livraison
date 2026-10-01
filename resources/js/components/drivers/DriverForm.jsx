import { useEffect, useState } from 'react';
import api, { errorMessage, fieldErrors } from '../../lib/api';
import { Alert, Button, Drawer, Field, Input, Textarea } from '../ui';

const EMPTY = { name: '', phone: '', email: '', city: '', vehicle: '', is_active: true, notes: '' };

export default function DriverForm({ open, driver, onClose, onSaved }) {
    const [form, setForm] = useState(EMPTY);
    const [errors, setErrors] = useState({});
    const [error, setError] = useState(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (!open) return;
        setErrors({});
        setError(null);
        setForm(driver ? { ...EMPTY, ...driver } : EMPTY);
    }, [open, driver]);

    const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value }));

    async function submit(e) {
        e?.preventDefault();
        setSaving(true);
        setErrors({});
        setError(null);
        try {
            const payload = { ...form };
            const { data } = driver ? await api.put(`/drivers/${driver.id}`, payload) : await api.post('/drivers', payload);
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
            title={driver ? 'Modifier le livreur' : 'Ajouter un livreur'}
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
            <form onSubmit={submit} className="space-y-5">
                <section className="space-y-3">
                    <h3 className="text-sm font-bold text-slate-800">Informations du compte</h3>
                    <Field label="Nom complet *" error={errors.name}>
                        <Input value={form.name} onChange={set('name')} required />
                    </Field>
                    <div className="grid grid-cols-2 gap-3">
                        <Field label="Téléphone" error={errors.phone}>
                            <Input value={form.phone || ''} onChange={set('phone')} />
                        </Field>
                        <Field label="E-mail" error={errors.email}>
                            <Input type="email" value={form.email || ''} onChange={set('email')} />
                        </Field>
                        <Field label="Ville" error={errors.city}>
                            <Input value={form.city || ''} onChange={set('city')} />
                        </Field>
                        <Field label="Véhicule" error={errors.vehicle}>
                            <Input value={form.vehicle || ''} onChange={set('vehicle')} placeholder="Moto, voiture…" />
                        </Field>
                    </div>
                    <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
                        <input type="checkbox" checked={!!form.is_active} onChange={set('is_active')} className="h-4 w-4 accent-blue-600" />
                        Livreur actif
                    </label>
                </section>

                {/* TARIFS_SECTION */}

                <Field label="Notes" error={errors.notes}>
                    <Textarea value={form.notes || ''} onChange={set('notes')} />
                </Field>
                <Alert>{error}</Alert>
                <button type="submit" className="hidden" />
            </form>
        </Drawer>
    );
}
