import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { RefreshCw, RotateCcw } from 'lucide-react';
import api, { errorMessage } from '../../lib/api';
import { Alert, Button, PageHeader, Select, Spinner } from '../../components/ui';

const STATUS_CLS = {
    success: 'text-emerald-700',
    failed: 'text-rose-600',
    waiting: 'text-amber-600',
    running: 'text-blue-600',
    cancelled: 'text-slate-500',
    skipped: 'text-slate-500',
    pending: 'text-slate-500',
};

function fmtDate(iso) {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' });
    } catch {
        return iso;
    }
}

/** Logs détaillés des exécutions + retry. */
export default function AutomationRuns() {
    const [items, setItems] = useState(null);
    const [selected, setSelected] = useState(null);
    const [status, setStatus] = useState('');
    const [error, setError] = useState(null);
    const [msg, setMsg] = useState(null);

    const load = useCallback(async () => {
        try {
            const { data } = await api.get('/automation-runs', {
                params: {
                    status: status || undefined,
                    exclude_simulation: 1,
                },
            });
            setItems(data.data);
            setError(null);
        } catch (e) {
            setError(errorMessage(e));
        }
    }, [status]);

    useEffect(() => {
        load();
    }, [load]);

    async function openRun(id) {
        try {
            const { data } = await api.get(`/automation-runs/${id}`);
            setSelected(data.data);
        } catch (e) {
            setError(errorMessage(e));
        }
    }

    async function retry(id) {
        setMsg(null);
        try {
            await api.post(`/automation-runs/${id}/retry`);
            setMsg('Réessai lancé.');
            await load();
            await openRun(id);
        } catch (e) {
            setError(errorMessage(e));
        }
    }

    return (
        <div className="space-y-5">
            <PageHeader
                title="Exécutions"
                subtitle="Logs détaillés des automatisations (conditions, actions, erreurs)."
                actions={
                    <>
                        <Select value={status} onChange={(e) => setStatus(e.target.value)} className="w-40">
                            <option value="">Tous les statuts</option>
                            <option value="success">Succès</option>
                            <option value="failed">Échec</option>
                            <option value="waiting">En attente</option>
                            <option value="running">En cours</option>
                            <option value="cancelled">Annulé</option>
                        </Select>
                        <Button variant="secondary" onClick={load}>
                            <RefreshCw className="h-4 w-4" /> Actualiser
                        </Button>
                    </>
                }
            />

            {error ? <Alert type="error">{error}</Alert> : null}
            {msg ? <Alert type="success">{msg}</Alert> : null}

            <div className="grid gap-4 lg:grid-cols-2">
                <div className="overflow-x-auto rounded-2xl border border-slate-200/80 bg-white">
                    {!items ? (
                        <Spinner />
                    ) : items.length === 0 ? (
                        <p className="px-4 py-10 text-center text-sm text-slate-400">Aucune exécution.</p>
                    ) : (
                        <table className="min-w-full text-left text-sm">
                            <thead className="border-b border-slate-100 bg-slate-50/80 text-xs font-semibold uppercase text-slate-500">
                                <tr>
                                    <th className="px-3 py-2">#</th>
                                    <th className="px-3 py-2">Automatisation</th>
                                    <th className="px-3 py-2">Sujet</th>
                                    <th className="px-3 py-2">Statut</th>
                                    <th className="px-3 py-2">Début</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {items.map((r) => (
                                    <tr
                                        key={r.id}
                                        className="cursor-pointer hover:bg-slate-50"
                                        onClick={() => openRun(r.id)}
                                    >
                                        <td className="px-3 py-2 font-mono text-xs">{r.id}</td>
                                        <td className="px-3 py-2 font-medium">{r.automation_name || r.automation_id}</td>
                                        <td className="px-3 py-2 text-slate-500">
                                            {r.subject_id ? `#${r.subject_id}` : '—'}
                                        </td>
                                        <td className={`px-3 py-2 font-semibold ${STATUS_CLS[r.status] || ''}`}>
                                            {r.status}
                                        </td>
                                        <td className="px-3 py-2 text-slate-500">{fmtDate(r.started_at || r.created_at)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>

                <div className="rounded-2xl border border-slate-200/80 bg-white p-4">
                    {!selected ? (
                        <p className="py-10 text-center text-sm text-slate-400">Sélectionnez une exécution.</p>
                    ) : (
                        <div className="space-y-3">
                            <div className="flex items-start justify-between gap-2">
                                <div>
                                    <h2 className="text-base font-bold text-slate-900">
                                        Run #{selected.id} · {selected.automation_name}
                                    </h2>
                                    <p className="text-xs text-slate-500">
                                        Déclencheur {selected.trigger_type} · sujet {selected.subject_id || '—'}
                                    </p>
                                </div>
                                {selected.status === 'failed' ? (
                                    <Button size="sm" variant="secondary" onClick={() => retry(selected.id)}>
                                        <RotateCcw className="h-3.5 w-3.5" /> Réessayer
                                    </Button>
                                ) : null}
                            </div>
                            {selected.error_message ? (
                                <Alert type="error">{selected.error_message}</Alert>
                            ) : null}
                            <div className="space-y-2">
                                {(selected.steps || []).map((s) => (
                                    <div key={s.id} className="rounded-xl border border-slate-100 bg-slate-50/80 p-3">
                                        <div className="flex items-center justify-between gap-2">
                                            <p className="font-mono text-xs font-semibold text-slate-700">
                                                {s.step_key} · {s.type}
                                            </p>
                                            <span className={`text-xs font-semibold ${STATUS_CLS[s.status] || ''}`}>
                                                {s.status}
                                            </span>
                                        </div>
                                        {s.error ? <p className="mt-1 text-xs text-rose-600">{s.error}</p> : null}
                                        <pre className="mt-2 max-h-40 overflow-auto rounded-lg bg-white p-2 text-[10px] text-slate-600">
                                            {JSON.stringify({ input: s.input_json, output: s.output_json }, null, 2)}
                                        </pre>
                                        <p className="mt-1 text-[10px] text-slate-400">
                                            {fmtDate(s.started_at)} → {fmtDate(s.finished_at)}
                                        </p>
                                    </div>
                                ))}
                            </div>
                            {selected.automation_id ? (
                                <Link
                                    to={`/automations/${selected.automation_id}/edit`}
                                    className="text-sm font-semibold text-blue-600 hover:underline"
                                >
                                    Ouvrir l’automatisation
                                </Link>
                            ) : null}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
