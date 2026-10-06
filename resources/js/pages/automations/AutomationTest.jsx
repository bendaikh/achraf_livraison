import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, FlaskConical } from 'lucide-react';
import api, { errorMessage } from '../../lib/api';
import { Alert, Button, Field, Input, PageHeader, Spinner } from '../../components/ui';

/** Test simulation : choisir une commande, exécuter sans effets de bord. */
export default function AutomationTest() {
    const { id } = useParams();
    const navigate = useNavigate();
    const [automation, setAutomation] = useState(null);
    const [orderId, setOrderId] = useState('');
    const [result, setResult] = useState(null);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        (async () => {
            try {
                const { data } = await api.get(`/automations/${id}`);
                setAutomation(data.data);
            } catch (e) {
                setError(errorMessage(e));
            }
        })();
    }, [id]);

    async function runTest() {
        setBusy(true);
        setError(null);
        setResult(null);
        try {
            const { data } = await api.post(`/automations/${id}/test`, { order_id: Number(orderId) });
            setResult(data.data);
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy(false);
        }
    }

    if (!automation && !error) return <Spinner />;

    return (
        <div className="space-y-5">
            <PageHeader
                title={`Tester · ${automation?.name || ''}`}
                subtitle="Mode simulation — aucune action réelle (WhatsApp, colis, etc.)."
                actions={
                    <>
                        <Button variant="secondary" onClick={() => navigate(`/automations/${id}/edit`)}>
                            <ArrowLeft className="h-4 w-4" /> Builder
                        </Button>
                        <Link
                            to="/automations/runs"
                            className="inline-flex h-10 items-center rounded-xl border border-slate-200 bg-white px-3.5 text-sm font-semibold text-slate-700"
                        >
                            Voir les logs
                        </Link>
                    </>
                }
            />

            {error ? <Alert type="error">{error}</Alert> : null}

            <div className="max-w-xl space-y-3 rounded-2xl border border-slate-200/80 bg-white p-4">
                <p className="text-sm text-slate-600">
                    Déclencheur : <strong>{automation?.trigger_type}</strong>
                </p>
                <Field label="ID commande (réelle)">
                    <Input
                        type="number"
                        value={orderId}
                        onChange={(e) => setOrderId(e.target.value)}
                        placeholder="Ex. 42"
                    />
                </Field>
                <Button onClick={runTest} disabled={busy || !orderId}>
                    <FlaskConical className="h-4 w-4" /> {busy ? 'Simulation…' : 'Lancer la simulation'}
                </Button>
            </div>

            {result ? (
                <div className="space-y-3 rounded-2xl border border-slate-200/80 bg-white p-4">
                    <h2 className="text-base font-bold text-slate-900">
                        Résultat · run #{result.run?.id} · {result.run?.status}
                    </h2>
                    <p className="text-xs text-slate-500">
                        Commande {result.order?.name} · ville {result.order?.city || '—'} · conf.{' '}
                        {result.order?.confirmation_status}
                    </p>
                    <div className="space-y-2">
                        {(result.preview || []).map((s, i) => (
                            <div key={i} className="rounded-xl border border-slate-100 bg-slate-50 p-3">
                                <p className="font-mono text-xs font-semibold">
                                    {s.step_key} · {s.type} · {s.status}
                                </p>
                                {s.error ? <p className="text-xs text-rose-600">{s.error}</p> : null}
                                <pre className="mt-1 max-h-36 overflow-auto text-[10px] text-slate-600">
                                    {JSON.stringify({ input: s.input, output: s.output }, null, 2)}
                                </pre>
                            </div>
                        ))}
                    </div>
                </div>
            ) : null}
        </div>
    );
}
