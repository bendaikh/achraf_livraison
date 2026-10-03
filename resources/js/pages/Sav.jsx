import { useCallback, useEffect, useState } from 'react';
import { Plus, Search } from 'lucide-react';
import api, { errorMessage } from '../lib/api';
import { formatDateTime } from '../lib/format';
import { Alert, Button, Card, EmptyState, Input, PageHeader, Select, Spinner } from '../components/ui';
import SavCreateDrawer from '../components/sav/SavCreateDrawer';
import SavDetailDrawer, { SavStatus } from '../components/sav/SavDetailDrawer';

const MAIN = ['to_assign', 'assigned', 'en_route', 'postponed', 'problem', 'picked_up', 'exchanged', 'returning', 'received'];

/** T7 — Retours / échanges (liste, création, suivi). */
export default function Sav() {
    const [status, setStatus] = useState('open');
    const [type, setType] = useState('');
    const [search, setSearch] = useState('');
    const [q, setQ] = useState('');
    const [data, setData] = useState(null);
    const [creating, setCreating] = useState(false);
    const [openId, setOpenId] = useState(null);
    const [error, setError] = useState(null);
    const [msg, setMsg] = useState(null);

    useEffect(() => {
        const t = setTimeout(() => setQ(search.trim()), 300);
        return () => clearTimeout(t);
    }, [search]);

    const load = useCallback(async () => {
        try {
            const { data: d } = await api.get('/sav', { params: { status, type: type || undefined, search: q || undefined } });
            setData(d);
        } catch (e) {
            setError(errorMessage(e));
        }
    }, [status, type, q]);
    useEffect(() => {
        load();
    }, [load]);

    const labels = Object.fromEntries((data?.statuses || []).map((s) => [s.value, s.label]));
    const chips = [['open', 'En cours', data?.open], ...MAIN.map((k) => [k, labels[k] || k, data?.counts?.[k] || 0]), ['closed', 'Clôturées', data?.counts?.closed || 0], ['cancelled', 'Annulées', data?.counts?.cancelled || 0], ['all', 'Toutes', null]];

    return (
        <div className="space-y-4">
            <PageHeader
                title="Retours & échanges"
                subtitle="Demandes SAV sur commandes livrées, suivies jusqu’au retour au dépôt."
                actions={
                    <Button onClick={() => setCreating(true)}>
                        <Plus className="h-4 w-4" /> Nouveau retour / échange
                    </Button>
                }
            />
            <Alert type="success">{msg}</Alert>
            <Alert>{error}</Alert>
            <Card bodyClassName="p-3 space-y-3">
                <div className="flex flex-col gap-2 sm:flex-row">
                    <div className="relative flex-1">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Réf. SAV, n° commande, client, téléphone…" className="pl-9" aria-label="Rechercher" />
                    </div>
                    <Select value={type} onChange={(e) => setType(e.target.value)} className="sm:max-w-44" aria-label="Type">
                        <option value="">Retours et échanges</option>
                        <option value="retour">Retours</option>
                        <option value="echange">Échanges</option>
                    </Select>
                </div>
                <div className="flex gap-1.5 overflow-x-auto pb-1">
                    {chips.map(([k, l, c]) => (
                        <button key={k} type="button" onClick={() => setStatus(k)} className={`shrink-0 rounded-lg px-3 py-1.5 text-xs font-semibold ${status === k ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}>
                            {l}
                            {c !== null && c !== undefined ? <span className={`ml-1.5 ${status === k ? 'text-blue-100' : 'text-slate-400'}`}>{c}</span> : null}
                        </button>
                    ))}
                </div>
            </Card>
            <Card bodyClassName="p-0">
                {!data ? (
                    <Spinner />
                ) : !data.data.length ? (
                    <EmptyState>Aucune demande.</EmptyState>
                ) : (
                    <div className="divide-y divide-slate-100">
                        {data.data.map((s) => (
                            <button key={s.id} type="button" onClick={() => setOpenId(s.id)} className="grid w-full gap-1 px-4 py-3 text-left text-sm hover:bg-slate-50 md:grid-cols-[150px_1fr_1.3fr_150px_140px] md:items-center md:gap-3">
                                <span>
                                    <span className="font-bold text-slate-900">{s.reference}</span>
                                    <span className={`ml-1.5 rounded px-1 text-[10px] font-bold ${s.type === 'echange' ? 'bg-violet-50 text-violet-700' : 'bg-orange-50 text-orange-700'}`}>{s.type_label}</span>
                                    <span className="block text-[11px] text-slate-400">{s.order_reference}</span>
                                </span>
                                <span className="min-w-0">
                                    <span className="block truncate font-semibold text-slate-800">{s.customer_name}</span>
                                    <span className="block truncate text-xs text-slate-400">{[s.phone, s.city].filter(Boolean).join(' · ')}</span>
                                </span>
                                <span className="min-w-0 text-xs text-slate-600">
                                    <span className="block truncate">↩ {s.pickup.map((i) => `${i.quantity}× ${i.title}${i.variant_title ? ` (${i.variant_title})` : ''}`).join(', ')}</span>
                                    {s.deliver.length ? <span className="block truncate text-emerald-700">↪ {s.deliver.map((i) => `${i.quantity}× ${i.title}${i.variant_title ? ` (${i.variant_title})` : ''}`).join(', ')}</span> : null}
                                    <span className="block truncate text-slate-400">{s.reason}</span>
                                </span>
                                <span className="text-xs text-slate-600">{s.driver_name ? <b>{s.driver_name}</b> : <span className="text-amber-700">Non attribuée</span>}</span>
                                <span className="flex flex-col items-start gap-0.5 md:items-end">
                                    <SavStatus sav={s} />
                                    <span className="text-[11px] text-slate-400">{formatDateTime(s.created_at)}</span>
                                </span>
                            </button>
                        ))}
                    </div>
                )}
            </Card>
            <SavCreateDrawer
                open={creating}
                onClose={() => setCreating(false)}
                onCreated={(d) => {
                    setCreating(false);
                    setMsg(d.message);
                    load();
                    setOpenId(d.data.id);
                }}
            />
            <SavDetailDrawer id={openId} onClose={() => setOpenId(null)} onChanged={load} />
        </div>
    );
}
