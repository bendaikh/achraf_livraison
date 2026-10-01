import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api, { errorMessage } from '../lib/api';
import { formatDH, formatDateTime } from '../lib/format';
import { Alert, Button, Card, Drawer, EmptyState, Field, Input, PageHeader, Spinner, Textarea } from '../components/ui';

export default function Closing() {
    const [pending, setPending] = useState(null);
    const [history, setHistory] = useState([]);
    const [closing, setClosing] = useState(null);
    const [form, setForm] = useState({ cod_remitted: '', note: '' });
    const [error, setError] = useState(null);
    const [msg, setMsg] = useState(null);
    const [saving, setSaving] = useState(false);

    const load = useCallback(async () => {
        try {
            const [p, h] = await Promise.all([api.get('/closings/pending'), api.get('/closings')]);
            setPending(p.data.data);
            setHistory(h.data.data);
        } catch (e) {
            setError(errorMessage(e));
        }
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    function open(row) {
        setClosing(row);
        setForm({ cod_remitted: row.cod, note: '' });
        setError(null);
    }

    async function submit() {
        setSaving(true);
        setError(null);
        try {
            await api.post('/closings', { driver_id: closing.driver.id, cod_remitted: Number(form.cod_remitted || 0), note: form.note || null });
            setMsg(`Caisse de ${closing.driver.name} clôturée.`);
            setClosing(null);
            load();
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setSaving(false);
        }
    }

    return (
        <div className="space-y-4">
            <PageHeader title="Clôture du jour" subtitle="Remise du COD par livreur. La rémunération des livreurs est suivie séparément et figée à la clôture." />
            <Alert type="success">{msg}</Alert>
            {!pending ? (
                <Spinner />
            ) : (
                <Card title="Caisses non clôturées" bodyClassName="p-0">
                    {pending.length === 0 ? (
                        <EmptyState>Aucune caisse à clôturer</EmptyState>
                    ) : (
                        <div className="divide-y divide-slate-100">
                            {pending.map((r) => (
                                <div key={r.driver.id} className="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:px-5">
                                    <div className="min-w-0 flex-1">
                                        <Link to={`/missions?driver_id=${r.driver.id}`} className="font-semibold text-slate-800 hover:text-blue-700">
                                            {r.driver.name}
                                        </Link>
                                        <div className="text-xs text-slate-500">
                                            {r.orders_count} commande(s) livrée(s) · {r.missions_count} mission(s) terminée(s)
                                            {r.oldest_delivered_at ? ` · depuis le ${formatDateTime(r.oldest_delivered_at.replace(' ', 'T'))}` : ''}
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-4 text-sm">
                                        <div>
                                            <div className="text-[10px] font-bold uppercase text-slate-400">COD à remettre</div>
                                            <div className="font-bold text-slate-900">{formatDH(r.cod)}</div>
                                        </div>
                                        <div>
                                            <div className="text-[10px] font-bold uppercase text-slate-400">Rémunération</div>
                                            <div className="font-semibold text-slate-700">{formatDH(r.commissions)}</div>
                                        </div>
                                        <Button size="sm" onClick={() => open(r)}>
                                            Clôturer
                                        </Button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </Card>
            )}

            <Card title="Historique des clôtures" bodyClassName="p-0">
                {history.length === 0 ? (
                    <EmptyState>Aucune clôture</EmptyState>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-left text-sm">
                            <thead>
                                <tr className="border-b border-slate-100 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                                    <th className="px-4 py-3">Date</th>
                                    <th className="px-3 py-3">Livreur</th>
                                    <th className="px-3 py-3 text-right">COD attendu</th>
                                    <th className="px-3 py-3 text-right">COD remis</th>
                                    <th className="px-3 py-3 text-right">Écart</th>
                                    <th className="px-3 py-3 text-right">Rémunération</th>
                                    <th className="px-4 py-3">Note</th>
                                </tr>
                            </thead>
                            <tbody>
                                {history.map((c) => (
                                    <tr key={c.id} className="border-b border-slate-50 last:border-0">
                                        <td className="whitespace-nowrap px-4 py-3 text-slate-600">{formatDateTime(c.closed_at)}</td>
                                        <td className="whitespace-nowrap px-3 py-3 font-semibold text-slate-800">{c.driver?.name}</td>
                                        <td className="whitespace-nowrap px-3 py-3 text-right">{formatDH(c.cod_expected)}</td>
                                        <td className="whitespace-nowrap px-3 py-3 text-right font-semibold">{formatDH(c.cod_remitted)}</td>
                                        <td className={`whitespace-nowrap px-3 py-3 text-right font-semibold ${c.gap > 0 ? 'text-rose-600' : 'text-slate-500'}`}>{formatDH(c.gap)}</td>
                                        <td className="whitespace-nowrap px-3 py-3 text-right">{formatDH(c.commissions_total)}</td>
                                        <td className="px-4 py-3 text-xs text-slate-500">{c.note || '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>

            <Drawer
                open={!!closing}
                onClose={() => setClosing(null)}
                title={closing ? `Clôturer la caisse de ${closing.driver.name}` : ''}
                footer={
                    <div className="flex justify-end gap-2">
                        <Button variant="secondary" onClick={() => setClosing(null)}>
                            Annuler
                        </Button>
                        <Button onClick={submit} disabled={saving}>
                            {saving ? 'Clôture…' : 'Valider la clôture'}
                        </Button>
                    </div>
                }
            >
                {closing ? (
                    <div className="space-y-3">
                        <div className="rounded-xl bg-slate-50 p-3 text-sm">
                            <div className="flex justify-between">
                                <span>COD attendu</span>
                                <b>{formatDH(closing.cod)}</b>
                            </div>
                            <div className="flex justify-between text-slate-500">
                                <span>Rémunération livreur (séparée)</span>
                                <span>{formatDH(closing.commissions)}</span>
                            </div>
                        </div>
                        <Field label="COD effectivement remis (DH)">
                            <Input type="number" min="0" step="0.01" value={form.cod_remitted} onChange={(e) => setForm({ ...form, cod_remitted: e.target.value })} />
                        </Field>
                        <Field label="Note">
                            <Textarea value={form.note} onChange={(e) => setForm({ ...form, note: e.target.value })} />
                        </Field>
                        <Alert>{error}</Alert>
                    </div>
                ) : null}
            </Drawer>
        </div>
    );
}
