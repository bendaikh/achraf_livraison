import { useCallback, useEffect, useState } from 'react';
import { Pencil, Plus, Power, Trash2 } from 'lucide-react';
import api, { errorMessage } from '../lib/api';
import { useMeta } from '../context/MetaContext';
import { Alert, Button, Card, Field, PageHeader, Select, Spinner } from '../components/ui';
import { StatusBadge } from '../components/ui/Badge';
import StatusForm from '../components/settings/StatusForm';

export default function StatusSettings() {
    const meta = useMeta();
    const [statuses, setStatuses] = useState(null);
    const [settings, setSettings] = useState(null);
    const [editing, setEditing] = useState(null);
    const [error, setError] = useState(null);
    const [msg, setMsg] = useState(null);

    const load = useCallback(async () => {
        try {
            const [{ data: st }, { data: se }] = await Promise.all([api.get('/delivery-statuses'), api.get('/settings')]);
            setStatuses(st.data);
            setSettings(se.data);
        } catch (e) {
            setError(errorMessage(e));
        }
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    async function refreshAll(message) {
        setEditing(null);
        await load();
        meta.reload(); // every screen picks up the new list immediately
        if (message) setMsg(message);
    }

    async function toggleActive(s) {
        setError(null);
        try {
            await api.put(`/delivery-statuses/${s.id}`, { is_active: !s.is_active });
            refreshAll(s.is_active ? `« ${s.name} » désactivé.` : `« ${s.name} » activé.`);
        } catch (e) {
            setError(errorMessage(e));
        }
    }

    async function remove(s) {
        if (!window.confirm(`Supprimer définitivement le statut « ${s.name} » ?`)) return;
        setError(null);
        try {
            await api.delete(`/delivery-statuses/${s.id}`);
            refreshAll(`« ${s.name} » supprimé.`);
        } catch (e) {
            setError(errorMessage(e));
        }
    }

    async function saveWorkflow(patch) {
        setError(null);
        try {
            const { data } = await api.put('/settings', patch);
            setSettings(data.data);
            setMsg('Workflow enregistré.');
        } catch (e) {
            setError(errorMessage(e));
        }
    }

    if (!statuses || !settings) return error ? <Alert>{error}</Alert> : <Spinner />;

    const fieldLabel = (v) => meta.requiredFieldCatalog.find((f) => f.value === v)?.label || v;
    const byId = Object.fromEntries(statuses.map((s) => [s.id, s]));
    const used = (s) => (s.usage_count || 0) + (s.history_count || 0) > 0;

    return (
        <div className="space-y-4">
            <PageHeader
                title="Statuts de livraison"
                subtitle="Les statuts sont utilisés automatiquement dans Commandes, Livreurs, Missions, Clôture, le tableau de bord et les filtres."
                actions={
                    <Button onClick={() => setEditing({})}>
                        <Plus className="h-4 w-4" /> Ajouter un statut
                    </Button>
                }
            />
            <Alert>{error}</Alert>
            <Alert type="success">{msg}</Alert>

            <Card bodyClassName="p-0" title="Liste des statuts" subtitle={`${statuses.length} statut(s) · ${statuses.filter((s) => s.is_active).length} actif(s)`}>
                <div className="divide-y divide-slate-100">
                    {statuses.map((s) => (
                        <div key={s.id} className={`flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:px-5 ${s.is_active ? '' : 'bg-slate-50/70'}`}>
                            <div className="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                                <span className="w-8 text-xs font-semibold text-slate-400">#{s.sort_order}</span>
                                <StatusBadge status={s} />
                                <code className="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-500">{s.code}</code>
                                <span className="text-xs font-medium text-slate-500">{meta.categoryMap[s.category]?.label || s.category}</span>
                                {!s.is_active ? <span className="rounded-full bg-slate-200 px-2 py-0.5 text-[10px] font-bold text-slate-600">Inactif</span> : null}
                            </div>
                            <div className="flex flex-wrap items-center gap-1.5 text-[11px] text-slate-500">
                                {(s.required_fields || []).map((f) => (
                                    <span key={f} className="rounded-full bg-amber-50 px-2 py-0.5 font-semibold text-amber-700">
                                        {fieldLabel(f)} requis
                                    </span>
                                ))}
                                {s.transition_to_ids?.length ? (
                                    <span title={s.transition_to_ids.map((id) => byId[id]?.name).join(', ')}>→ {s.transition_to_ids.length} transition(s)</span>
                                ) : null}
                                <span>{s.usage_count || 0} cde(s)</span>
                            </div>
                            <div className="flex items-center gap-1.5">
                                <Button size="sm" variant="secondary" onClick={() => setEditing(s)}>
                                    <Pencil className="h-3.5 w-3.5" /> Modifier
                                </Button>
                                <Button size="sm" variant="secondary" onClick={() => toggleActive(s)} title={s.is_active ? 'Désactiver' : 'Activer'}>
                                    <Power className="h-3.5 w-3.5" /> {s.is_active ? 'Désactiver' : 'Activer'}
                                </Button>
                                {!used(s) ? (
                                    <Button size="sm" variant="ghost" onClick={() => remove(s)} title="Supprimer (jamais utilisé)">
                                        <Trash2 className="h-3.5 w-3.5 text-rose-600" />
                                    </Button>
                                ) : null}
                            </div>
                        </div>
                    ))}
                </div>
            </Card>

            <Card title="Workflow" subtitle="Statuts automatiques et contrôle des transitions autorisées.">
                <div className="grid gap-3 md:grid-cols-3">
                    <Field label="Statut appliqué à la confirmation">
                        <Select value={settings.status_on_confirm_id || ''} onChange={(e) => saveWorkflow({ status_on_confirm_id: e.target.value ? Number(e.target.value) : null })}>
                            <option value="">Premier statut « Avant livraison »</option>
                            {statuses.filter((s) => s.is_active).map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field label="Statut appliqué à l’attribution d’un livreur">
                        <Select value={settings.status_on_assign_id || ''} onChange={(e) => saveWorkflow({ status_on_assign_id: e.target.value ? Number(e.target.value) : null })}>
                            <option value="">Aucun changement</option>
                            {statuses.filter((s) => s.is_active).map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field label="Contrôle des transitions" hint="Si activé, seules les transitions définies sur chaque statut sont proposées.">
                        <label className="flex h-10 items-center gap-2 text-sm font-medium text-slate-700">
                            <input
                                type="checkbox"
                                checked={!!settings.enforce_status_transitions}
                                onChange={(e) => saveWorkflow({ enforce_status_transitions: e.target.checked })}
                                className="h-4 w-4 accent-blue-600"
                            />
                            Activer le contrôle du workflow
                        </label>
                    </Field>
                </div>
            </Card>

            <StatusForm
                open={editing !== null}
                status={editing && editing.id ? editing : null}
                allStatuses={statuses}
                onClose={() => setEditing(null)}
                onSaved={() => refreshAll('Statut enregistré.')}
            />
        </div>
    );
}
