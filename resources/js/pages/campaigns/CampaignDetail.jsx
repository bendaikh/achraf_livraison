import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Pause, Play, RefreshCw } from 'lucide-react';
import api, { errorMessage } from '../../lib/api';
import { useAuth } from '../../contexts/AuthContext';
import { Alert, Button, Card, Spinner } from '../../components/ui';

const STATUS_LABEL = {
    draft: 'Brouillon',
    scheduled: 'Programmée',
    running: 'En cours',
    completed: 'Terminée',
    paused: 'Suspendue',
    error: 'Erreur',
    archived: 'Archivée',
};

function Stat({ label, value, onClick, tone = '' }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`rounded-2xl border border-slate-200/80 bg-white px-3 py-2.5 text-left shadow-sm transition hover:border-emerald-300 ${tone}`}
        >
            <div className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{label}</div>
            <div className="text-xl font-bold text-slate-900">{value}</div>
        </button>
    );
}

export default function CampaignDetail() {
    const { id } = useParams();
    const navigate = useNavigate();
    const { can } = useAuth();
    const [campaign, setCampaign] = useState(null);
    const [recipients, setRecipients] = useState([]);
    const [statusFilter, setStatusFilter] = useState('');
    const [error, setError] = useState(null);
    const [msg, setMsg] = useState(null);
    const [busy, setBusy] = useState(false);

    const load = useCallback(async () => {
        try {
            const [{ data }, { data: rec }] = await Promise.all([
                api.get(`/whatsapp/campaigns/${id}`),
                api.get(`/whatsapp/campaigns/${id}/recipients`, { params: { status: statusFilter || undefined, per_page: 50 } }),
            ]);
            setCampaign(data.data);
            setRecipients(rec.data || []);
            setError(null);
        } catch (e) {
            setError(errorMessage(e));
        }
    }, [id, statusFilter]);

    useEffect(() => {
        load();
        const t = setInterval(load, 8000);
        return () => clearInterval(t);
    }, [load]);

    async function act(fn, success) {
        setBusy(true);
        setError(null);
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

    if (!campaign) return error ? <Alert>{error}</Alert> : <Spinner />;

    const remaining = campaign.pending_count || 0;

    return (
        <div className="space-y-4">
            <button type="button" onClick={() => navigate('/whatsapp/campagnes')} className="inline-flex items-center gap-1 text-sm font-semibold text-slate-500 hover:text-slate-800">
                <ArrowLeft className="h-4 w-4" /> Campagnes
            </button>
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="text-xl font-bold text-slate-900 sm:text-2xl">{campaign.name}</h1>
                    <p className="mt-1 text-sm text-slate-500">
                        {STATUS_LABEL[campaign.status] || campaign.status}
                        {campaign.account ? ` · ${campaign.account.phone_number}` : ''}
                        {campaign.template ? ` · ${campaign.template.name}` : ''}
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Button variant="secondary" onClick={load} disabled={busy}><RefreshCw className="h-4 w-4" /> Actualiser</Button>
                    {can('campaigns.manage') && campaign.editable ? (
                        <Button variant="secondary" onClick={() => navigate(`/whatsapp/campagnes/${id}/edit`)}>Modifier</Button>
                    ) : null}
                    {can('campaigns.send') && campaign.status === 'running' ? (
                        <Button variant="secondary" disabled={busy} onClick={() => act(() => api.post(`/whatsapp/campaigns/${id}/pause`), 'Suspendue.')}>
                            <Pause className="h-4 w-4" /> Suspendre
                        </Button>
                    ) : null}
                    {can('campaigns.send') && campaign.status === 'paused' ? (
                        <Button disabled={busy} onClick={() => act(() => api.post(`/whatsapp/campaigns/${id}/resume`), 'Reprise.')}>
                            <Play className="h-4 w-4" /> Reprendre
                        </Button>
                    ) : null}
                    {can('campaigns.send') ? (
                        <Button variant="secondary" disabled={busy} onClick={() => act(() => api.post(`/whatsapp/campaigns/${id}/retry-failed`), 'Échecs retriables remis en file.')}>
                            Retraiter échecs
                        </Button>
                    ) : null}
                </div>
            </div>
            <Alert type="success">{msg}</Alert>
            <Alert>{error}</Alert>
            {campaign.error_message ? <Alert>{campaign.error_message}</Alert> : null}

            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-8">
                <Stat label="Destinataires" value={campaign.recipients_count} onClick={() => setStatusFilter('')} />
                <Stat label="En attente" value={remaining} onClick={() => setStatusFilter('pending')} />
                <Stat label="Envoyés" value={campaign.sent_count} onClick={() => setStatusFilter('sent')} />
                <Stat label="Délivrés" value={campaign.delivered_count} onClick={() => setStatusFilter('delivered')} />
                <Stat label="Lus" value={campaign.read_count} onClick={() => setStatusFilter('read')} />
                <Stat label="Échecs" value={campaign.failed_count} onClick={() => setStatusFilter('failed')} tone="text-rose-700" />
                <Stat label="Exclus" value={campaign.excluded_count} onClick={() => setStatusFilter('excluded')} />
                <Stat label="Progression" value={`${campaign.recipients_count ? Math.round((campaign.sent_count / campaign.recipients_count) * 100) : 0}%`} />
            </div>

            {campaign.exclusion_reasons?.length ? (
                <Card title="Exclusions" bodyClassName="p-3">
                    <ul className="flex flex-wrap gap-2 text-sm">
                        {campaign.exclusion_reasons.map((r) => (
                            <li key={r.reason} className="rounded-md bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600">
                                {r.reason} : {r.count}
                            </li>
                        ))}
                    </ul>
                </Card>
            ) : null}

            <Card title="Destinataires" subtitle={statusFilter ? `Filtre : ${statusFilter}` : 'Tous'} bodyClassName="p-0">
                <div className="overflow-x-auto">
                    <table className="min-w-full text-left text-sm">
                        <thead>
                            <tr className="border-b border-slate-100 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                                <th className="px-4 py-2">Client</th>
                                <th className="px-2 py-2">Téléphone</th>
                                <th className="px-2 py-2">Statut</th>
                                <th className="px-2 py-2">Envoyé</th>
                                <th className="px-2 py-2">Délivré</th>
                                <th className="px-2 py-2">Lu</th>
                                <th className="px-4 py-2">Erreur</th>
                            </tr>
                        </thead>
                        <tbody>
                            {recipients.map((r) => (
                                <tr key={r.id} className="border-b border-slate-50 last:border-0">
                                    <td className="px-4 py-2">
                                        <Link to={`/clients/${r.phone_key}`} className="font-semibold text-blue-700 hover:underline">
                                            {r.customer_name || r.phone_key}
                                        </Link>
                                    </td>
                                    <td className="px-2 py-2 text-xs text-slate-500">{r.phone}</td>
                                    <td className="px-2 py-2 text-xs font-semibold">{r.status}</td>
                                    <td className="px-2 py-2 text-xs text-slate-500">{r.sent_at ? new Date(r.sent_at).toLocaleString('fr-FR') : '—'}</td>
                                    <td className="px-2 py-2 text-xs text-slate-500">{r.delivered_at ? new Date(r.delivered_at).toLocaleString('fr-FR') : '—'}</td>
                                    <td className="px-2 py-2 text-xs text-slate-500">{r.read_at ? new Date(r.read_at).toLocaleString('fr-FR') : '—'}</td>
                                    <td className="max-w-[16rem] truncate px-4 py-2 text-xs text-rose-600">{r.error_message || r.exclude_reason || '—'}</td>
                                </tr>
                            ))}
                            {recipients.length === 0 ? (
                                <tr><td colSpan={7} className="px-4 py-8 text-center text-slate-400">Aucun destinataire pour ce filtre.</td></tr>
                            ) : null}
                        </tbody>
                    </table>
                </div>
            </Card>
        </div>
    );
}
