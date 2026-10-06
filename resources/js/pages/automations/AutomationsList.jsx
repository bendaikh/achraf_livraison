import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import {
    Archive,
    Copy,
    FlaskConical,
    Pause,
    Pencil,
    Play,
    Plus,
    RefreshCw,
    Workflow,
} from 'lucide-react';
import api, { errorMessage } from '../../lib/api';
import { Alert, Button, PageHeader, Spinner } from '../../components/ui';

const STATUS_BADGE = {
    draft: 'bg-slate-100 text-slate-700',
    active: 'bg-emerald-50 text-emerald-700',
    paused: 'bg-amber-50 text-amber-700',
    error: 'bg-rose-50 text-rose-700',
};

function StatCard({ label, value }) {
    return (
        <div className="rounded-2xl border border-slate-200/80 bg-white px-4 py-3 shadow-sm shadow-slate-200/40">
            <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{label}</p>
            <p className="mt-1 text-2xl font-bold text-slate-900">{value ?? '—'}</p>
        </div>
    );
}

function fmtDate(iso) {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' });
    } catch {
        return iso;
    }
}

/** Automatisations — liste + stats + actions ligne. */
export default function AutomationsList() {
    const navigate = useNavigate();
    const [items, setItems] = useState(null);
    const [stats, setStats] = useState(null);
    const [error, setError] = useState(null);
    const [msg, setMsg] = useState(null);
    const [busy, setBusy] = useState(false);

    const load = useCallback(async () => {
        try {
            const [listRes, statsRes] = await Promise.all([
                api.get('/automations'),
                api.get('/automations/stats'),
            ]);
            setItems(listRes.data.data);
            setStats(statsRes.data.data);
            setError(null);
        } catch (e) {
            setError(errorMessage(e));
        }
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    async function act(fn, success) {
        setBusy(true);
        setError(null);
        setMsg(null);
        try {
            await fn();
            setMsg(success);
            await load();
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy(false);
        }
    }

    return (
        <div className="space-y-5">
            <PageHeader
                title="Automatisations"
                subtitle="Scénarios QUAND → SI → ALORS → ATTENDRE → ACTIONS, isolés par société."
                actions={
                    <>
                        <Button variant="secondary" onClick={load} disabled={busy}>
                            <RefreshCw className="h-4 w-4" /> Actualiser
                        </Button>
                        <Button onClick={() => navigate('/automations/new')}>
                            <Plus className="h-4 w-4" /> Créer une automatisation
                        </Button>
                    </>
                }
            />

            {error ? <Alert type="error">{error}</Alert> : null}
            {msg ? <Alert type="success">{msg}</Alert> : null}

            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <StatCard label="Total" value={stats?.total} />
                <StatCard label="Actives" value={stats?.active} />
                <StatCard label="Exécutions 24h" value={stats?.runs_24h} />
                <StatCard label="Erreurs 24h" value={stats?.errors_24h} />
                <StatCard label="En attente" value={stats?.waiting} />
            </div>

            {!items ? (
                <div className="flex justify-center py-16">
                    <Spinner />
                </div>
            ) : items.length === 0 ? (
                <div className="rounded-2xl border border-dashed border-slate-200 bg-white px-6 py-12 text-center">
                    <Workflow className="mx-auto h-10 w-10 text-slate-300" />
                    <p className="mt-3 text-sm font-semibold text-slate-700">Aucune automatisation</p>
                    <p className="mt-1 text-xs text-slate-400">Créez un scénario ou installez un modèle depuis le builder.</p>
                    <Button className="mt-4" onClick={() => navigate('/automations/new')}>
                        <Plus className="h-4 w-4" /> Créer
                    </Button>
                </div>
            ) : (
                <div className="overflow-x-auto rounded-2xl border border-slate-200/80 bg-white shadow-sm">
                    <table className="min-w-full text-left text-sm">
                        <thead className="border-b border-slate-100 bg-slate-50/80 text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-4 py-3">Nom</th>
                                <th className="px-4 py-3">Déclencheur</th>
                                <th className="px-4 py-3">Intégrations</th>
                                <th className="px-4 py-3">Créée</th>
                                <th className="px-4 py-3">Dernière exéc.</th>
                                <th className="px-4 py-3">Exéc.</th>
                                <th className="px-4 py-3">OK</th>
                                <th className="px-4 py-3">Erreurs</th>
                                <th className="px-4 py-3">Statut</th>
                                <th className="px-4 py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {items.map((a) => (
                                <tr key={a.id} className="hover:bg-slate-50/60">
                                    <td className="px-4 py-3 font-semibold text-slate-900">
                                        <Link to={`/automations/${a.id}/edit`} className="hover:text-blue-600">
                                            {a.name}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3 text-slate-600">{a.trigger_type}</td>
                                    <td className="px-4 py-3 text-slate-500">
                                        {(a.integrations || []).join(', ') || '—'}
                                    </td>
                                    <td className="px-4 py-3 text-slate-500">{fmtDate(a.created_at)}</td>
                                    <td className="px-4 py-3 text-slate-500">{fmtDate(a.last_run_at)}</td>
                                    <td className="px-4 py-3">{a.runs_count}</td>
                                    <td className="px-4 py-3 text-emerald-700">{a.success_count}</td>
                                    <td className="px-4 py-3 text-rose-600">{a.error_count}</td>
                                    <td className="px-4 py-3">
                                        <span
                                            className={`inline-flex rounded-lg px-2 py-0.5 text-xs font-semibold ${STATUS_BADGE[a.status] || STATUS_BADGE.draft}`}
                                        >
                                            {a.status_label || a.status}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="flex flex-wrap gap-1">
                                            <button
                                                type="button"
                                                title="Modifier"
                                                className="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100"
                                                onClick={() => navigate(`/automations/${a.id}/edit`)}
                                            >
                                                <Pencil className="h-4 w-4" />
                                            </button>
                                            <button
                                                type="button"
                                                title="Dupliquer"
                                                className="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100"
                                                onClick={() =>
                                                    act(
                                                        () => api.post(`/automations/${a.id}/duplicate`),
                                                        'Automatisation dupliquée.',
                                                    )
                                                }
                                            >
                                                <Copy className="h-4 w-4" />
                                            </button>
                                            <button
                                                type="button"
                                                title="Tester"
                                                className="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100"
                                                onClick={() => navigate(`/automations/${a.id}/test`)}
                                            >
                                                <FlaskConical className="h-4 w-4" />
                                            </button>
                                            {a.status === 'active' ? (
                                                <button
                                                    type="button"
                                                    title="Désactiver"
                                                    className="rounded-lg p-1.5 text-amber-600 hover:bg-amber-50"
                                                    onClick={() =>
                                                        act(
                                                            () => api.post(`/automations/${a.id}/pause`),
                                                            'Automatisation mise en pause.',
                                                        )
                                                    }
                                                >
                                                    <Pause className="h-4 w-4" />
                                                </button>
                                            ) : (
                                                <button
                                                    type="button"
                                                    title="Activer"
                                                    className="rounded-lg p-1.5 text-emerald-600 hover:bg-emerald-50"
                                                    onClick={() =>
                                                        act(
                                                            () => api.post(`/automations/${a.id}/activate`),
                                                            'Automatisation activée.',
                                                        )
                                                    }
                                                >
                                                    <Play className="h-4 w-4" />
                                                </button>
                                            )}
                                            <button
                                                type="button"
                                                title="Archiver"
                                                className="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100"
                                                onClick={() => {
                                                    if (!window.confirm(`Archiver « ${a.name} » ?`)) return;
                                                    act(
                                                        () => api.post(`/automations/${a.id}/archive`),
                                                        'Automatisation archivée.',
                                                    );
                                                }}
                                            >
                                                <Archive className="h-4 w-4" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}
