import { useCallback, useEffect, useState } from 'react';
import { GripVertical, Pencil, Plus, Power, Trash2 } from 'lucide-react';
import api, { errorMessage } from '../lib/api';
import { useMeta } from '../context/MetaContext';
import { Alert, Button, Card, Field, Input, PageHeader, Select, Spinner, Textarea } from '../components/ui';

const PALETTE = ['#2563eb', '#d97706', '#f97316', '#ea580c', '#16a34a', '#059669', '#dc2626', '#7c3aed', '#0ea5e9', '#64748b'];
const ICONS = [
    ['', 'Aucune'],
    ['package-x', 'Colis / stock'],
    ['clock', 'Horloge'],
    ['calendar-clock', 'Rappel'],
    ['phone-off', 'Pas de réponse'],
    ['check-circle', 'Confirmé'],
    ['x-circle', 'Annulé'],
    ['alert-triangle', 'Alerte'],
];

const FLAGS = [
    ['stays_in_queue', 'Reste dans la file de confirmation'],
    ['counts_as_confirmed', 'Considéré comme commande confirmée'],
    ['counts_as_failure', 'Considéré comme échec'],
    ['requires_recall_date', 'Nécessite une date de rappel'],
    ['requires_time', 'Nécessite une heure'],
    ['requires_reason', 'Nécessite un motif'],
    ['requires_comment', 'Nécessite un commentaire'],
    ['requires_product', 'Nécessite un produit concerné'],
    ['is_final', 'Statut final'],
    ['show_in_filters', 'Afficher comme onglet principal'],
];

const emptyForm = () => ({
    name: '',
    color: '#f97316',
    icon: 'package-x',
    sort_order: '',
    is_active: true,
    category: 'personnalise',
    reason_options_text: '',
    stays_in_queue: false,
    counts_as_confirmed: false,
    counts_as_failure: false,
    requires_recall_date: false,
    requires_time: false,
    requires_reason: false,
    requires_comment: false,
    requires_product: false,
    is_final: false,
    show_in_filters: false,
});

function fromStatus(s) {
    return {
        ...emptyForm(),
        ...s,
        reason_options_text: (s.reason_options || []).join('\n'),
    };
}

export default function ConfirmationStatusSettings() {
    const meta = useMeta();
    const [statuses, setStatuses] = useState(null);
    const [categories, setCategories] = useState({});
    const [editing, setEditing] = useState(null);
    const [error, setError] = useState(null);
    const [msg, setMsg] = useState(null);
    const [dragId, setDragId] = useState(null);

    const load = useCallback(async () => {
        const { data } = await api.get('/settings/confirmation-statuses');
        setStatuses(data.statuses || []);
        setCategories(data.categories || {});
    }, []);

    useEffect(() => {
        load().catch((e) => setError(errorMessage(e)));
    }, [load]);

    async function refresh(message) {
        setEditing(null);
        await load();
        meta.reload?.();
        if (message) setMsg(message);
    }

    async function toggleActive(s) {
        setError(null);
        try {
            await api.put(`/settings/confirmation-statuses/${s.id}`, { is_active: !s.is_active });
            await refresh(s.is_active ? `« ${s.name} » désactivé.` : `« ${s.name} » activé.`);
        } catch (e) {
            setError(errorMessage(e));
        }
    }

    async function remove(s) {
        if (!window.confirm(`Supprimer définitivement le statut « ${s.name} » ?`)) return;
        setError(null);
        try {
            await api.delete(`/settings/confirmation-statuses/${s.id}`);
            await refresh(`« ${s.name} » supprimé.`);
        } catch (e) {
            setError(errorMessage(e));
        }
    }

    async function dropOn(targetId) {
        if (!dragId || dragId === targetId || !statuses) return;
        const ids = statuses.map((s) => s.id);
        const from = ids.indexOf(dragId);
        const to = ids.indexOf(targetId);
        if (from < 0 || to < 0) return;
        ids.splice(to, 0, ids.splice(from, 1)[0]);
        setStatuses(ids.map((id) => statuses.find((s) => s.id === id)));
        setDragId(null);
        try {
            await api.post('/settings/confirmation-statuses/reorder', { order: ids });
            await load();
        } catch (e) {
            setError(errorMessage(e));
            await load();
        }
    }

    if (!statuses) return error ? <Alert>{error}</Alert> : <Spinner />;

    return (
        <div className="space-y-4">
            <PageHeader
                title="Statuts de confirmation"
                subtitle="Paramètres → Statuts de confirmation. Chaque société a sa propre liste. Un statut utilisé ne se supprime pas : désactivez-le."
                actions={
                    <Button onClick={() => setEditing(emptyForm())}>
                        <Plus className="h-4 w-4" /> + Nouveau statut
                    </Button>
                }
            />
            <Alert>{error}</Alert>
            <Alert type="success">{msg}</Alert>

            {editing ? (
                <StatusForm
                    form={editing}
                    categories={categories}
                    onChange={setEditing}
                    onCancel={() => setEditing(null)}
                    onSaved={(message) => refresh(message)}
                    onError={setError}
                />
            ) : null}

            <Card bodyClassName="p-0" title="Liste des statuts" subtitle={`${statuses.length} statut(s) · ${statuses.filter((s) => s.is_active).length} actif(s)`}>
                <div className="divide-y divide-slate-100">
                    {statuses.map((s) => (
                        <div
                            key={s.id}
                            draggable
                            onDragStart={() => setDragId(s.id)}
                            onDragOver={(e) => e.preventDefault()}
                            onDrop={() => dropOn(s.id)}
                            className={`flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:px-5 ${s.is_active ? '' : 'bg-slate-50/70'}`}
                        >
                            <button type="button" className="cursor-grab text-slate-300" aria-label="Réordonner" title="Glisser pour réordonner">
                                <GripVertical className="h-4 w-4" />
                            </button>
                            <div className="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                                <span className="h-3.5 w-3.5 rounded-full ring-1 ring-black/10" style={{ background: s.color || '#64748b' }} />
                                <span className="font-semibold text-slate-900">{s.name}</span>
                                <span className="text-xs font-medium text-slate-500">{s.category_label || categories[s.category] || s.category}</span>
                                {!s.is_active ? <span className="rounded-full bg-slate-200 px-2 py-0.5 text-[10px] font-bold text-slate-600">inactif</span> : null}
                                {s.is_system ? <span className="rounded-full bg-blue-50 px-2 py-0.5 text-[10px] font-bold text-blue-700">système</span> : null}
                            </div>
                            <div className="flex flex-wrap items-center gap-1.5 text-[11px] text-slate-500">
                                {(s.flags_summary || []).map((flag) => (
                                    <span key={flag} className="rounded-full bg-slate-100 px-2 py-0.5 font-semibold text-slate-600">{flag}</span>
                                ))}
                                <span>{s.usage_count || 0} utilisation(s)</span>
                            </div>
                            <div className="flex items-center gap-1.5">
                                <Button size="sm" variant="secondary" onClick={() => setEditing(fromStatus(s))}>
                                    <Pencil className="h-3.5 w-3.5" /> Modifier
                                </Button>
                                <Button size="sm" variant="secondary" onClick={() => toggleActive(s)} title={s.is_active ? 'Désactiver' : 'Activer'}>
                                    <Power className="h-3.5 w-3.5" /> {s.is_active ? 'Désactiver' : 'Activer'}
                                </Button>
                                {s.can_delete ? (
                                    <Button size="sm" variant="ghost" onClick={() => remove(s)} title="Supprimer (jamais utilisé)">
                                        <Trash2 className="h-3.5 w-3.5" />
                                    </Button>
                                ) : null}
                            </div>
                        </div>
                    ))}
                </div>
            </Card>
        </div>
    );
}

function StatusForm({ form, categories, onChange, onCancel, onSaved, onError }) {
    const set = (patch) => onChange({ ...form, ...patch });
    const [busy, setBusy] = useState(false);
    const isEdit = Boolean(form.id);

    async function save() {
        setBusy(true);
        onError(null);
        const payload = {
            name: form.name,
            color: form.color,
            icon: form.icon || null,
            sort_order: form.sort_order === '' ? undefined : Number(form.sort_order),
            is_active: Boolean(form.is_active),
            category: form.category,
            show_in_filters: Boolean(form.show_in_filters),
            stays_in_queue: Boolean(form.stays_in_queue),
            counts_as_confirmed: Boolean(form.counts_as_confirmed),
            counts_as_failure: Boolean(form.counts_as_failure),
            requires_recall_date: Boolean(form.requires_recall_date),
            requires_time: Boolean(form.requires_time),
            requires_reason: Boolean(form.requires_reason),
            requires_comment: Boolean(form.requires_comment),
            requires_product: Boolean(form.requires_product),
            is_final: Boolean(form.is_final),
            reason_options: String(form.reason_options_text || '')
                .split('\n')
                .map((line) => line.trim())
                .filter(Boolean),
        };
        try {
            if (isEdit) {
                await api.put(`/settings/confirmation-statuses/${form.id}`, payload);
                onSaved(`« ${form.name} » enregistré.`);
            } else {
                await api.post('/settings/confirmation-statuses', payload);
                onSaved(`« ${form.name} » créé. Il apparaît dans le Centre sans redéploiement.`);
            }
        } catch (e) {
            onError(errorMessage(e));
        } finally {
            setBusy(false);
        }
    }

    return (
        <Card title={isEdit ? `Modifier « ${form.name} »` : 'Nouveau statut'}>
            <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Nom">
                    <Input value={form.name} onChange={(e) => set({ name: e.target.value })} placeholder="Rupture de stock" />
                </Field>
                <Field label="Catégorie">
                    <Select value={form.category} disabled={form.is_system} onChange={(e) => set({ category: e.target.value })}>
                        {Object.entries(categories).map(([code, label]) => (
                            <option key={code} value={code}>{label}</option>
                        ))}
                    </Select>
                </Field>
                <Field label="Couleur">
                    <div className="flex flex-wrap items-center gap-1.5">
                        {PALETTE.map((hex) => (
                            <button key={hex} type="button" aria-label={hex} onClick={() => set({ color: hex })} className={`h-6 w-6 rounded-full ring-2 ${form.color === hex ? 'ring-slate-900' : 'ring-transparent'}`} style={{ background: hex }} />
                        ))}
                        <Input className="w-28" value={form.color} onChange={(e) => set({ color: e.target.value })} placeholder="#f97316" />
                    </div>
                </Field>
                <Field label="Icône">
                    <Select value={form.icon || ''} onChange={(e) => set({ icon: e.target.value })}>
                        {ICONS.map(([value, label]) => (
                            <option key={value || 'none'} value={value}>{label}</option>
                        ))}
                    </Select>
                </Field>
                <Field label="Ordre">
                    <Input type="number" value={form.sort_order} onChange={(e) => set({ sort_order: e.target.value })} />
                </Field>
                <Field label="Actif">
                    <label className="inline-flex items-center gap-2 text-sm font-medium text-slate-700">
                        <input type="checkbox" checked={Boolean(form.is_active)} onChange={(e) => set({ is_active: e.target.checked })} />
                        Statut actif
                    </label>
                </Field>
                <div className="sm:col-span-2 grid gap-2 sm:grid-cols-2">
                    {FLAGS.map(([key, label]) => (
                        <label key={key} className="flex items-center gap-2 text-sm text-slate-700">
                            <input type="checkbox" checked={Boolean(form[key])} onChange={(e) => set({ [key]: e.target.checked })} />
                            {label}
                        </label>
                    ))}
                </div>
                <Field label="Motifs (un par ligne, facultatif)" className="sm:col-span-2">
                    <Textarea rows={3} value={form.reason_options_text} onChange={(e) => set({ reason_options_text: e.target.value })} placeholder="Rupture&#10;Casse" />
                </Field>
            </div>
            <div className="mt-4 flex gap-2">
                <Button disabled={busy || !form.name?.trim()} onClick={save}>{isEdit ? 'Enregistrer' : 'Créer le statut'}</Button>
                <Button variant="secondary" onClick={onCancel}>Fermer</Button>
            </div>
        </Card>
    );
}
