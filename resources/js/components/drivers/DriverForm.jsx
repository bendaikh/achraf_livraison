import { useEffect, useState } from 'react';
import api, { errorMessage, fieldErrors } from '../../lib/api';
import { useMeta } from '../../context/MetaContext';
import { Alert, Button, Drawer, Field, Input, Textarea } from '../ui';

const EMPTY = { name: '', phone: '', email: '', password: '', city: '', vehicle: '', is_active: true, notes: '' };

function tariffsToForm(tariffs = {}) {
    return Object.fromEntries(Object.entries(tariffs || {}).map(([type, v]) => [`tariff_${type}`, v ?? '']));
}

export default function DriverForm({ open, driver, onClose, onSaved, focusTariffs = false }) {
    const meta = useMeta();
    const [form, setForm] = useState(EMPTY);
    const [errors, setErrors] = useState({});
    const [error, setError] = useState(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (!open) return;
        setErrors({});
        setError(null);
        // New driver: tariffs prefilled with the company defaults (Paramètres), still editable.
        setForm(driver ? { ...EMPTY, ...driver, password: '', ...tariffsToForm(driver.tariffs) } : { ...EMPTY, ...tariffsToForm(meta.defaultTariffs) });
        if (focusTariffs) setTimeout(() => document.getElementById('driver-tariffs')?.scrollIntoView({ behavior: 'smooth' }), 50);
    }, [open, driver, meta.defaultTariffs, focusTariffs]);

    const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value }));

    async function submit(e) {
        e?.preventDefault();
        setSaving(true);
        setErrors({});
        setError(null);
        try {
            const { tariffs, stats, created_at, id, user_id, has_account, ...payload } = form; // eslint-disable-line no-unused-vars
            if (!payload.password) delete payload.password;
            meta.missionTypes.forEach((t) => {
                const k = `tariff_${t.value}`;
                payload[k] = payload[k] === '' || payload[k] === undefined ? null : Number(payload[k]);
            });
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
                        <Field label="Ville" error={errors.city}>
                            <Input value={form.city || ''} onChange={set('city')} />
                        </Field>
                        <Field label="Véhicule" error={errors.vehicle}>
                            <Input value={form.vehicle || ''} onChange={set('vehicle')} placeholder="Moto, voiture…" />
                        </Field>
                    </div>
                    <div className="grid grid-cols-1 gap-3 rounded-xl border border-slate-200 p-3 sm:grid-cols-2">
                        <p className="text-[11px] text-slate-500 sm:col-span-2">
                            Accès Lav'Fast Flow du livreur (rôle Livreur) : il se connecte avec cet e-mail pour voir « Mes missions ».
                        </p>
                        <Field label="E-mail de connexion *" error={errors.email}>
                            <Input type="email" value={form.email || ''} onChange={set('email')} required autoComplete="off" />
                        </Field>
                        <Field label={driver ? 'Nouveau mot de passe (optionnel)' : 'Mot de passe *'} error={errors.password}>
                            <Input type="password" value={form.password || ''} onChange={set('password')} required={!driver} minLength={8} autoComplete="new-password" />
                        </Field>
                    </div>
                    <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
                        <input type="checkbox" checked={!!form.is_active} onChange={set('is_active')} className="h-4 w-4 accent-blue-600" />
                        Compte actif
                    </label>
                </section>

                <section id="driver-tariffs" className="space-y-3 rounded-2xl border border-blue-100 bg-blue-50/40 p-3">
                    <div>
                        <h3 className="text-sm font-bold text-slate-800">Tarifs des missions</h3>
                        <p className="text-[11px] text-slate-500">
                            {driver
                                ? 'Montant payé au livreur par mission. Une modification ne s’applique qu’aux nouvelles missions attribuées.'
                                : 'Préremplis avec les tarifs par défaut de la société — modifiables pour ce livreur.'}
                        </p>
                    </div>
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        {meta.missionTypes.map((t) => {
                            const k = `tariff_${t.value}`;
                            return (
                                <Field key={k} label={t.label} error={errors[k]}>
                                    <div className="relative">
                                        <Input type="number" min="0" step="0.5" value={form[k] ?? ''} onChange={set(k)} className="pr-10" />
                                        <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs font-semibold text-slate-400">DH</span>
                                    </div>
                                </Field>
                            );
                        })}
                    </div>
                </section>

                <Field label="Notes" error={errors.notes}>
                    <Textarea value={form.notes || ''} onChange={set('notes')} />
                </Field>
                <Alert>{error}</Alert>
                <button type="submit" className="hidden" />
            </form>
        </Drawer>
    );
}
