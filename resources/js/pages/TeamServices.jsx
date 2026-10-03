import { useCallback, useEffect, useState } from 'react';
import { Plus, Save, Trash2 } from 'lucide-react';
import api, { errorMessage } from '../lib/api';
import { Alert, Button, Card, Checkbox, Input, PageHeader, Spinner } from '../components/ui';

/** Paramètres → Équipe & rémunération → Services (T6): editable list, nothing hardcoded. */
export default function TeamServices() {
    const [rows, setRows] = useState(null);
    const [error, setError] = useState(null);
    const [msg, setMsg] = useState(null);
    const [draft, setDraft] = useState({ name: '', description: '' });

    const load = useCallback(async () => {
        try {
            const { data } = await api.get('/services');
            setRows(data.data);
        } catch (e) {
            setError(errorMessage(e));
        }
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    async function run(fn) {
        setError(null);
        try {
            const { data } = await fn();
            setMsg(data.message);
            await load();
        } catch (e) {
            setError(errorMessage(e));
        }
    }

    const update = (id, k, v) => setRows((r) => r.map((s) => (s.id === id ? { ...s, [k]: v, dirty: true } : s)));

    return (
        <div className="space-y-4">
            <PageHeader title="Équipe & rémunération" subtitle="Services de l’équipe (Confirmation, Commercial, SAV…). La rémunération se règle sur chaque utilisateur." />
            <Alert type="success">{msg}</Alert>
            <Alert>{error}</Alert>
            <Card title="Services">
                {!rows ? (
                    <Spinner />
                ) : (
                    <div className="space-y-2">
                        {rows.map((s) => (
                            <div key={s.id} className="grid items-center gap-2 rounded-xl border border-slate-100 p-2 sm:grid-cols-[1fr_2fr_auto_auto_auto]">
                                <Input value={s.name} onChange={(e) => update(s.id, 'name', e.target.value)} aria-label="Nom du service" />
                                <Input value={s.description || ''} onChange={(e) => update(s.id, 'description', e.target.value)} placeholder="Description" aria-label="Description" />
                                <label className="flex items-center gap-1.5 text-xs font-semibold text-slate-600">
                                    <Checkbox checked={!!s.is_active} onChange={(e) => update(s.id, 'is_active', e.target.checked)} /> Actif
                                </label>
                                <span className="text-xs text-slate-400">{s.users_count} utilisateur(s)</span>
                                <div className="flex gap-1">
                                    <Button size="sm" variant="secondary" disabled={!s.dirty} onClick={() => run(() => api.put(`/services/${s.id}`, { name: s.name, description: s.description, is_active: !!s.is_active }))}>
                                        <Save className="h-3.5 w-3.5" />
                                    </Button>
                                    <Button size="sm" variant="ghost" disabled={s.users_count > 0} title={s.users_count > 0 ? 'Service utilisé : désactivez-le' : 'Supprimer'} onClick={() => window.confirm(`Supprimer « ${s.name} » ?`) && run(() => api.delete(`/services/${s.id}`))}>
                                        <Trash2 className="h-3.5 w-3.5" />
                                    </Button>
                                </div>
                            </div>
                        ))}
                        <div className="grid items-center gap-2 rounded-xl border border-dashed border-slate-200 p-2 sm:grid-cols-[1fr_2fr_auto]">
                            <Input value={draft.name} onChange={(e) => setDraft({ ...draft, name: e.target.value })} placeholder="Nouveau service" aria-label="Nouveau service" />
                            <Input value={draft.description} onChange={(e) => setDraft({ ...draft, description: e.target.value })} placeholder="Description" />
                            <Button size="sm" disabled={!draft.name.trim()} onClick={() => run(() => api.post('/services', draft)).then(() => setDraft({ name: '', description: '' }))}>
                                <Plus className="h-3.5 w-3.5" /> Ajouter
                            </Button>
                        </div>
                    </div>
                )}
            </Card>
        </div>
    );
}
