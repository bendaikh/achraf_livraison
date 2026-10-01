import { useEffect, useState } from 'react';
import api, { errorMessage, fieldErrors } from '../../lib/api';
import { useMeta } from '../../context/MetaContext';
import { Alert, Button, Drawer, Field, Input, Select } from '../ui';
import { ColorBadge } from '../ui/Badge';
import { ICONS } from '../ui/StatusIcon';

const PRESETS = ['#64748b', '#6366f1', '#2563eb', '#0ea5e9', '#0d9488', '#16a34a', '#84cc16', '#f59e0b', '#ea580c', '#dc2626', '#e11d48', '#a855f7'];

function slugify(v) {
    return v
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .slice(0, 60);
}

export default function StatusForm({ open, status, allStatuses, onClose, onSaved }) {
    const meta = useMeta();
    const [form, setForm] = useState(null);
    const [codeTouched, setCodeTouched] = useState(false);
    const [errors, setErrors] = useState({});
    const [error, setError] = useState(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (!open) return;
        setErrors({});
        setError(null);
        setCodeTouched(!!status);
        setForm(
            status
                ? { ...status, required_fields: status.required_fields || [], transition_to_ids: status.transition_to_ids || [] }
                : {
                      name: '',
                      code: '',
                      color: '#2563eb',
                      icon: '',
                      sort_order: '',
                      is_active: true,
                      category: meta.statusCategories[0]?.value || '',
                      required_fields: [],
                      creates_mission_type: '',
                      transition_to_ids: [],
                  },
        );
    }, [open, status, meta.statusCategories]);

    if (!form) return null;
    const set = (k, v) => setForm((f) => ({ ...f, [k]: v }));
    const toggleIn = (k, v) => setForm((f) => ({ ...f, [k]: f[k].includes(v) ? f[k].filter((x) => x !== v) : [...f[k], v] }));

    async function submit(e) {
        e?.preventDefault();
        setSaving(true);
        setError(null);
        setErrors({});
        try {
            const payload = {
                name: form.name,
                code: form.code,
                color: form.color,
                icon: form.icon || null,
                sort_order: form.sort_order === '' ? null : Number(form.sort_order),
                is_active: !!form.is_active,
                category: form.category,
                required_fields: form.required_fields,
                creates_mission_type: form.creates_mission_type || null,
            };
            const { data } = status ? await api.put(`/delivery-statuses/${status.id}`, payload) : await api.post('/delivery-statuses', payload);
            await api.put(`/delivery-statuses/${data.data.id}/transitions`, { to_status_ids: form.transition_to_ids });
            onSaved?.();
        } catch (err) {
            setErrors(fieldErrors(err));
            setError(errorMessage(err));
        } finally {
            setSaving(false);
        }
    }

    const others = allStatuses.filter((s) => s.id !== status?.id);

    return (
        <Drawer
            open={open}
            onClose={onClose}
            title={status ? 'Modifier le statut' : 'Ajouter un statut'}
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
            <form onSubmit={submit} className="space-y-4">
                <div className="flex items-center gap-2 rounded-xl bg-slate-50 p-3 text-sm text-slate-500">
                    Aperçu : <ColorBadge color={form.color} label={form.name || 'Nom du statut'} icon={form.icon} />
                </div>
                <div className="grid grid-cols-2 gap-3">
                    <Field label="Nom du statut *" error={errors.name} className="col-span-2">
                        <Input
                            value={form.name}
                            onChange={(e) => {
                                const v = e.target.value;
                                setForm((f) => ({ ...f, name: v, code: codeTouched ? f.code : slugify(v) }));
                            }}
                            required
                        />
                    </Field>
                    <Field label="Code interne unique *" error={errors.code} hint="Minuscules, chiffres et _ uniquement.">
                        <Input
                            value={form.code}
                            onChange={(e) => {
                                setCodeTouched(true);
                                set('code', e.target.value);
                            }}
                        />
                    </Field>
                    <Field label="Catégorie *" error={errors.category} hint="Sert au calcul des statistiques.">
                        <Select value={form.category} onChange={(e) => set('category', e.target.value)}>
                            {meta.statusCategories.map((c) => (
                                <option key={c.value} value={c.value}>
                                    {c.label}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field label="Ordre d’affichage" error={errors.sort_order}>
                        <Input type="number" min="0" value={form.sort_order ?? ''} onChange={(e) => set('sort_order', e.target.value)} placeholder="Auto" />
                    </Field>
                    <Field label="État">
                        <label className="flex h-10 items-center gap-2 text-sm font-medium text-slate-700">
                            <input type="checkbox" checked={!!form.is_active} onChange={(e) => set('is_active', e.target.checked)} className="h-4 w-4 accent-blue-600" />
                            Actif
                        </label>
                    </Field>
                </div>

                <Field label="Couleur *" error={errors.color}>
                    <div className="flex flex-wrap items-center gap-2">
                        {PRESETS.map((c) => (
                            <button
                                key={c}
                                type="button"
                                onClick={() => set('color', c)}
                                className={`h-7 w-7 rounded-full ring-offset-2 ${form.color === c ? 'ring-2 ring-slate-800' : ''}`}
                                style={{ backgroundColor: c }}
                                aria-label={c}
                            />
                        ))}
                        <input type="color" value={form.color} onChange={(e) => set('color', e.target.value)} className="h-8 w-10 cursor-pointer rounded border border-slate-200" />
                    </div>
                </Field>

                <Field label="Icône (optionnelle)">
                    <div className="flex flex-wrap gap-1.5">
                        <button
                            type="button"
                            onClick={() => set('icon', '')}
                            className={`h-9 rounded-lg border px-2 text-xs font-semibold ${!form.icon ? 'border-blue-400 bg-blue-50 text-blue-700' : 'border-slate-200 text-slate-500'}`}
                        >
                            Aucune
                        </button>
                        {Object.entries(ICONS).map(([name, Icon]) => (
                            <button
                                key={name}
                                type="button"
                                onClick={() => set('icon', name)}
                                title={name}
                                className={`inline-flex h-9 w-9 items-center justify-center rounded-lg border ${form.icon === name ? 'border-blue-400 bg-blue-50 text-blue-700' : 'border-slate-200 text-slate-500 hover:bg-slate-50'}`}
                            >
                                <Icon className="h-4 w-4" />
                            </button>
                        ))}
                    </div>
                </Field>

                <Field label="Champs obligatoires lors du passage à ce statut">
                    <div className="grid grid-cols-2 gap-1.5">
                        {meta.requiredFieldCatalog.map((f) => (
                            <label key={f.value} className="flex items-center gap-2 rounded-lg border border-slate-200 px-2.5 py-2 text-sm">
                                <input type="checkbox" checked={form.required_fields.includes(f.value)} onChange={() => toggleIn('required_fields', f.value)} className="h-4 w-4 accent-blue-600" />
                                {f.label}
                            </label>
                        ))}
                    </div>
                </Field>

                <Field label="Mission générée automatiquement (livreur de la commande)" error={errors.creates_mission_type}>
                    <Select value={form.creates_mission_type || ''} onChange={(e) => set('creates_mission_type', e.target.value)}>
                        <option value="">Aucune</option>
                        {meta.missionTypes
                            .filter((t) => (meta.statusMissionTypes || []).includes(t.value))
                            .map((t) => (
                                <option key={t.value} value={t.value}>
                                    {t.label}
                                </option>
                            ))}
                    </Select>
                </Field>

                <Field label="Transitions autorisées vers" hint="Appliquées uniquement si le contrôle du workflow est activé.">
                    <div className="flex flex-wrap gap-1.5">
                        {others.map((s) => {
                            const on = form.transition_to_ids.includes(s.id);
                            return (
                                <button
                                    key={s.id}
                                    type="button"
                                    onClick={() => toggleIn('transition_to_ids', s.id)}
                                    className="rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset"
                                    style={on ? { backgroundColor: s.color, color: '#fff', '--tw-ring-color': s.color } : { color: s.color, '--tw-ring-color': `${s.color}55` }}
                                >
                                    {s.name}
                                </button>
                            );
                        })}
                    </div>
                </Field>

                <Alert>{error}</Alert>
                <button type="submit" className="hidden" />
            </form>
        </Drawer>
    );
}
