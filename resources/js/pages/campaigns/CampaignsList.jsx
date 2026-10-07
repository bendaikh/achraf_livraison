import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Archive, Copy, Megaphone, Pause, Pencil, Play, Plus, RefreshCw } from 'lucide-react';
import api, { errorMessage } from '../../lib/api';
import { useAuth } from '../../contexts/AuthContext';
import { Alert, Button, PageHeader, Spinner } from '../../components/ui';

const STATUS_BADGE = {
    draft: 'bg-slate-100 text-slate-700',
    scheduled: 'bg-sky-50 text-sky-700',
    running: 'bg-emerald-50 text-emerald-700',
    completed: 'bg-indigo-50 text-indigo-700',
    paused: 'bg-amber-50 text-amber-700',
    error: 'bg-rose-50 text-rose-700',
    archived: 'bg-slate-100 text-slate-500',
};

const STATUS_LABEL = {
    draft: 'Brouillon',
    scheduled: 'Programmée',
    running: 'En cours',
    completed: 'Terminée',
    paused: 'Suspendue',
    error: 'Erreur',
    archived: 'Archivée',
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

export default function CampaignsList() {
    const navigate = useNavigate();
    const { can } = useAuth();
    const [items, setItems] = useState(null);
    const [stats, setStats] = useState(null);
    const [error, setError] = useState(null);
    const [msg, setMsg] = useState(null);
    const [busy, setBusy] = useState(false);

    const load = useCallback(async () => {
        try {
            const [listRes, statsRes] = await Promise.all([
                api.get('/whatsapp/campaigns'),
                api.get('/whatsapp/campaigns/stats'),
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

    if (!items) return error ? <Alert>{error}</Alert> : <Spinner />;

    return (
        <div className="space-y-4">
            <PageHeader
                title="Campagnes WhatsApp"
                subtitle="Envois marketing par template, audience et file d’attente"
                actions={
                    <div className="flex flex-wrap gap-2">
                        <Button variant="secondary" onClick={load} disabled={busy}>
                            <RefreshCw className="h-4 w-4" /> Actualiser
                        </Button>
                        {can('campaigns.manage') ? (
                            <Button onClick={() => navigate('/whatsapp/campagnes/new')}>
                                <Plus className="h-4 w-4" /> Créer une campagne
                            </Button>
                        ) : null}
                    </div>
                }
            />
            <Alert type="success">{msg}</Alert>
            <Alert>{error}</Alert>

            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-7">
                <StatCard label="Total" value={stats?.total} />
                <StatCard label="Brouillons" value={stats?.draft} />
                <StatCard label="Programmées" value={stats?.scheduled} />
                <StatCard label="En cours" value={stats?.running} />
                <StatCard label="Terminées" value={stats?.completed} />
                <StatCard label="Suspendues" value={stats?.paused} />
                <StatCard label="Erreurs" value={stats?.error} />
            </div>

            <div className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm">
                <div className="overflow-x-auto">
                    <table className="min-w-full text-left text-sm">
                        <thead>
                            <tr className="border-b border-slate-100 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                                <th className="px-4 py-3">Nom</th>
                                <th className="px-2 py-3">Expéditeur</th>
                                <th className="px-2 py-3">Template</th>
                                <th className="px-2 py-3">Créateur</th>
                                <th className="px-2 py-3">Création</th>
                                <th className="px-2 py-3">Envoi</th>
                                <th className="px-2 py-3 text-right">Dest.</th>
                                <th className="px-2 py-3 text-right">Envoyés</th>
                                <th className="px-2 py-3 text-right">Délivrés</th>
                                <th className="px-2 py-3 text-right">Lus</th>
                                <th className="px-2 py-3 text-right">Échecs</th>
                                <th className="px-2 py-3">Statut</th>
                                <th className="px-4 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {items.length === 0 ? (
                                <tr>
                                    <td colSpan={13} className="px-4 py-10 text-center text-slate-400">
                                        <Megaphone className="mx-auto mb-2 h-8 w-8 opacity-40" />
                                        Aucune campagne. Créez-en une pour démarrer.
                                    </td>
                                </tr>
                            ) : (
                                items.map((c) => (
                                    <tr key={c.id} className="border-b border-slate-50 last:border-0 hover:bg-slate-50/60">
                                        <td className="px-4 py-2.5">
                                            <Link to={`/whatsapp/campagnes/${c.id}`} className="font-semibold text-slate-900 hover:text-emerald-700">
                                                {c.name}
                                            </Link>
                                        </td>
                                        <td className="px-2 py-2.5 text-xs text-slate-600">{c.account?.phone_number || c.account?.display_name || '—'}</td>
                                        <td className="px-2 py-2.5 text-xs text-slate-600">{c.template?.name || '—'}</td>
                                        <td className="px-2 py-2.5 text-xs text-slate-600">{c.creator?.name || '—'}</td>
                                        <td className="whitespace-nowrap px-2 py-2.5 text-xs text-slate-500">{fmtDate(c.created_at)}</td>
                                        <td className="whitespace-nowrap px-2 py-2.5 text-xs text-slate-500">{fmtDate(c.started_at || c.scheduled_at)}</td>
                                        <td className="px-2 py-2.5 text-right tabular-nums">{c.recipients_count}</td>
                                        <td className="px-2 py-2.5 text-right tabular-nums">{c.sent_count}</td>
                                        <td className="px-2 py-2.5 text-right tabular-nums">{c.delivered_count}</td>
                                        <td className="px-2 py-2.5 text-right tabular-nums">{c.read_count}</td>
                                        <td className="px-2 py-2.5 text-right tabular-nums text-rose-600">{c.failed_count}</td>
                                        <td className="px-2 py-2.5">
                                            <span className={`rounded-md px-1.5 py-0.5 text-[11px] font-semibold ${STATUS_BADGE[c.status] || STATUS_BADGE.draft}`}>
                                                {STATUS_LABEL[c.status] || c.status}
                                            </span>
                                        </td>
                                        <td className="px-4 py-2.5">
                                            <div className="flex flex-wrap justify-end gap-1">
                                                <Button size="sm" variant="secondary" onClick={() => navigate(`/whatsapp/campagnes/${c.id}`)}>
                                                    Ouvrir
                                                </Button>
                                                {can('campaigns.manage') && c.editable ? (
                                                    <Button size="sm" variant="secondary" onClick={() => navigate(`/whatsapp/campagnes/${c.id}/edit`)}>
                                                        <Pencil className="h-3.5 w-3.5" />
                                                    </Button>
                                                ) : null}
                                                {can('campaigns.manage') ? (
                                                    <Button
                                                        size="sm"
                                                        variant="secondary"
                                                        disabled={busy}
                                                        onClick={() => act(() => api.post(`/whatsapp/campaigns/${c.id}/duplicate`), 'Campagne dupliquée.')}
                                                    >
                                                        <Copy className="h-3.5 w-3.5" />
                                                    </Button>
                                                ) : null}
                                                {can('campaigns.send') && c.status === 'running' ? (
                                                    <Button size="sm" variant="secondary" disabled={busy} onClick={() => act(() => api.post(`/whatsapp/campaigns/${c.id}/pause`), 'Suspendue.')}>
                                                        <Pause className="h-3.5 w-3.5" />
                                                    </Button>
                                                ) : null}
                                                {can('campaigns.send') && c.status === 'paused' ? (
                                                    <Button size="sm" variant="secondary" disabled={busy} onClick={() => act(() => api.post(`/whatsapp/campaigns/${c.id}/resume`), 'Reprise.')}>
                                                        <Play className="h-3.5 w-3.5" />
                                                    </Button>
                                                ) : null}
                                                {can('campaigns.manage') && c.status !== 'running' && c.status !== 'archived' ? (
                                                    <Button size="sm" variant="secondary" disabled={busy} onClick={() => act(() => api.post(`/whatsapp/campaigns/${c.id}/archive`), 'Archivée.')}>
                                                        <Archive className="h-3.5 w-3.5" />
                                                    </Button>
                                                ) : null}
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}
