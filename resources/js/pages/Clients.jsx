import { useCallback, useEffect, useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { ChevronLeft, ChevronRight, FolderPlus, Pencil, Plus, Search, Trash2, Users } from 'lucide-react';
import api, { errorMessage } from '../lib/api';
import { formatDate, formatDH } from '../lib/format';
import { useAuth } from '../contexts/AuthContext';
import { Alert, Button, Card, Drawer, EmptyState, Field, Input, PageHeader, Select, Spinner } from '../components/ui';
import { BlockedClientAlert, SegmentBadges } from '../components/clients/ClientBadges';

const TAB_BY_PATH = { '/clients': 'all', '/clients/bloques': 'blocked', '/clients/segments': 'segment', '/clients/groupes': 'group' };
const TITLES = { all: 'Tous les clients', blocked: 'Clients bloqués', segment: 'Segments', group: 'Groupes' };

function rateCls(v, bad) {
    if (!v) return 'text-slate-400';
    return bad ? (v >= 30 ? 'text-rose-600' : 'text-slate-700') : v >= 70 ? 'text-emerald-600' : 'text-slate-700';
}

/** T9 — Clients module (derived from orders, identified by phone). */
export default function Clients() {
    const { pathname } = useLocation();
    const navigate = useNavigate();
    const { can } = useAuth();
    const tab = TAB_BY_PATH[pathname] || 'all';
    const [summary, setSummary] = useState(null);
    const [segment, setSegment] = useState('');
    const [groupId, setGroupId] = useState('');
    const [search, setSearch] = useState('');
    const [q, setQ] = useState('');
    const [page, setPage] = useState(1);
    const [data, setData] = useState(null);
    const [selected, setSelected] = useState([]);
    const [error, setError] = useState(null);
    const [msg, setMsg] = useState(null);
    const [groupForm, setGroupForm] = useState(null);
    const [target, setTarget] = useState('');

    const loadSummary = useCallback(() => api.get('/clients/summary').then(({ data: d }) => setSummary(d)), []);
    useEffect(() => {
        loadSummary();
    }, [loadSummary]);
    useEffect(() => {
        const t = setTimeout(() => setQ(search.trim()), 300);
        return () => clearTimeout(t);
    }, [search]);
    useEffect(() => {
        setPage(1);
        setSelected([]);
    }, [tab, segment, groupId, q]);
    useEffect(() => {
        if (tab === 'segment' && !segment && summary?.segments?.length) setSegment(summary.segments[0].key);
        if (tab === 'group' && !groupId && summary?.groups?.length) setGroupId(String(summary.groups[0].id));
    }, [tab, summary, segment, groupId]);

    const load = useCallback(async () => {
        if (tab === 'segment' && !segment) return;
        if (tab === 'group' && !groupId) {
            setData({ data: [], meta: { total: 0, current_page: 1, last_page: 1 } });
            return;
        }
        try {
            const { data: d } = await api.get('/clients', { params: { tab, segment: tab === 'segment' ? segment : undefined, group_id: tab === 'group' ? groupId : undefined, search: q || undefined, page } });
            setData(d);
        } catch (e) {
            setError(errorMessage(e));
        }
    }, [tab, segment, groupId, q, page]);
    useEffect(() => {
        setData(null);
        load();
    }, [load]);

    async function run(fn) {
        setError(null);
        try {
            const { data: d } = await fn();
            setMsg(d.message);
            await Promise.all([load(), loadSummary()]);
            return d;
        } catch (e) {
            setError(errorMessage(e));
            return null;
        }
    }

    const segments = summary?.segments || [];
    const groups = summary?.groups || [];
    const rows = data?.data || [];
    const toggle = (k) => setSelected((s) => (s.includes(k) ? s.filter((x) => x !== k) : [...s, k]));
    const currentGroup = groups.find((g) => String(g.id) === String(groupId));

    return (
        <div className="space-y-4">
            <PageHeader
                title={TITLES[tab]}
                subtitle="Clients identifiés par numéro de téléphone, calculés à partir des commandes réelles."
                actions={
                    summary ? (
                        <div className="flex gap-2 text-xs font-semibold text-slate-500">
                            <span className="rounded-lg bg-white px-2.5 py-1.5 shadow-sm ring-1 ring-slate-200">{summary.all} clients</span>
                            <span className="rounded-lg bg-white px-2.5 py-1.5 text-rose-600 shadow-sm ring-1 ring-slate-200">{summary.blocked} bloqué(s)</span>
                        </div>
                    ) : null
                }
            />
            <Alert type="success">{msg}</Alert>
            <Alert>{error}</Alert>

            {tab === 'segment' ? (
                <div className="grid grid-cols-2 gap-2 lg:grid-cols-5">
                    {segments.map((s) => (
                        <button key={s.key} type="button" onClick={() => setSegment(s.key)} className={`rounded-2xl border bg-white p-3 text-left shadow-sm transition ${segment === s.key ? 'border-blue-400 ring-2 ring-blue-100' : 'border-slate-200 hover:border-slate-300'}`}>
                            <div className="flex items-center justify-between">
                                <span className="text-xs font-bold" style={{ color: s.color }}>
                                    {s.label}
                                </span>
                                <span className="text-lg font-bold text-slate-900">{s.count}</span>
                            </div>
                            <p className="mt-1 text-[11px] leading-snug text-slate-400">{s.description}</p>
                        </button>
                    ))}
                </div>
            ) : null}

            {tab === 'group' ? (
                <Card bodyClassName="p-3">
                    <div className="flex flex-wrap items-center gap-2">
                        {groups.map((g) => (
                            <button key={g.id} type="button" onClick={() => setGroupId(String(g.id))} className={`inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold ${String(g.id) === String(groupId) ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}>
                                <span className="h-2 w-2 rounded-full" style={{ background: g.color }} /> {g.name} <span className="opacity-70">{g.count}</span>
                            </button>
                        ))}
                        {!groups.length ? <span className="text-sm text-slate-400">Aucun groupe. Créez-en un puis ajoutez des clients depuis « Tous les clients ».</span> : null}
                        {can('clients.groups') ? (
                            <div className="ml-auto flex gap-1">
                                {currentGroup ? (
                                    <>
                                        <Button size="sm" variant="ghost" onClick={() => setGroupForm({ ...currentGroup })} title="Modifier le groupe">
                                            <Pencil className="h-3.5 w-3.5" />
                                        </Button>
                                        <Button size="sm" variant="ghost" title="Supprimer le groupe" onClick={() => window.confirm(`Supprimer le groupe « ${currentGroup.name} » ?`) && run(() => api.delete(`/client-groups/${currentGroup.id}`)).then(() => setGroupId(''))}>
                                            <Trash2 className="h-3.5 w-3.5" />
                                        </Button>
                                    </>
                                ) : null}
                                <Button size="sm" onClick={() => setGroupForm({ name: '', color: '#2563eb', description: '' })}>
                                    <Plus className="h-3.5 w-3.5" /> Nouveau groupe
                                </Button>
                            </div>
                        ) : null}
                    </div>
                </Card>
            ) : null}

            <Card bodyClassName="p-3">
                <div className="relative">
                    <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Nom, téléphone, email…" className="pl-9" aria-label="Rechercher un client" />
                </div>
            </Card>

            {selected.length && can('clients.groups') ? (
                <div className="sticky top-16 z-20 flex flex-wrap items-center justify-between gap-2 rounded-2xl border border-blue-200 bg-blue-50 px-4 py-2.5 text-sm shadow-sm">
                    <span className="font-semibold text-blue-800">{selected.length} client(s) sélectionné(s)</span>
                    <div className="flex flex-wrap items-center gap-2">
                        {tab === 'group' && currentGroup ? (
                            <Button size="sm" variant="secondary" onClick={() => Promise.all(selected.map((k) => api.delete(`/client-groups/${currentGroup.id}/members/${k}`))).then(() => run(() => Promise.resolve({ data: { message: 'Clients retirés du groupe.' } })).then(() => setSelected([])))}>
                                Retirer du groupe
                            </Button>
                        ) : (
                            <>
                                <Select value={target} onChange={(e) => setTarget(e.target.value)} className="max-w-48" aria-label="Groupe">
                                    <option value="">Choisir un groupe…</option>
                                    {groups.map((g) => (
                                        <option key={g.id} value={g.id}>
                                            {g.name}
                                        </option>
                                    ))}
                                </Select>
                                <Button size="sm" disabled={!target} onClick={() => run(() => api.post(`/client-groups/${target}/members`, { keys: selected })).then(() => setSelected([]))}>
                                    <FolderPlus className="h-3.5 w-3.5" /> Ajouter au groupe
                                </Button>
                            </>
                        )}
                        <Button size="sm" variant="ghost" onClick={() => setSelected([])}>
                            Désélectionner
                        </Button>
                    </div>
                </div>
            ) : null}

            <Card bodyClassName="p-0">
                {!data ? (
                    <Spinner />
                ) : !rows.length ? (
                    <EmptyState>{tab === 'blocked' ? 'Aucun client bloqué.' : 'Aucun client.'}</EmptyState>
                ) : (
                    <>
                        <div className="hidden overflow-x-auto md:block">
                            <table className="min-w-full text-left text-sm">
                                <thead>
                                    <tr className="border-b border-slate-100 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                                        {can('clients.groups') ? <th className="w-8 py-2.5 pl-4" /> : null}
                                        <th className="px-3 py-2.5">Client</th>
                                        <th className="px-2 py-2.5">Ville</th>
                                        <th className="px-2 py-2.5 text-right">Cmd</th>
                                        <th className="px-2 py-2.5 text-right">Livrées</th>
                                        <th className="px-2 py-2.5 text-right">Retours</th>
                                        <th className="px-2 py-2.5 text-right">Annul.</th>
                                        <th className="px-2 py-2.5 text-right">Montant</th>
                                        <th className="px-2 py-2.5 text-right">Confirm.</th>
                                        <th className="px-2 py-2.5 text-right">Livraison</th>
                                        <th className="px-2 py-2.5 text-right">Retour</th>
                                        <th className="px-2 py-2.5">Dernière cmd</th>
                                        <th className="px-3 py-2.5">Segments</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((c) => (
                                        <tr key={c.key} className="cursor-pointer border-b border-slate-50 last:border-0 hover:bg-slate-50/70" onClick={() => navigate(`/clients/${c.key}`)}>
                                            {can('clients.groups') ? (
                                                <td className="py-2 pl-4" onClick={(e) => e.stopPropagation()}>
                                                    <input type="checkbox" checked={selected.includes(c.key)} onChange={() => toggle(c.key)} aria-label={`Sélectionner ${c.name}`} />
                                                </td>
                                            ) : null}
                                            <td className="px-3 py-2">
                                                <div className="flex items-center gap-1.5 font-semibold text-slate-800">
                                                    {c.name} <BlockedClientAlert block={c.blocked} compact />
                                                </div>
                                                <div className="text-xs text-slate-400">{c.phone}</div>
                                            </td>
                                            <td className="px-2 py-2 text-slate-600">{c.city || '—'}</td>
                                            <td className="px-2 py-2 text-right font-semibold tabular-nums">{c.orders}</td>
                                            <td className="px-2 py-2 text-right tabular-nums text-emerald-700">{c.delivered}</td>
                                            <td className="px-2 py-2 text-right tabular-nums text-rose-600">{c.returned || <span className="text-slate-300">0</span>}</td>
                                            <td className="px-2 py-2 text-right tabular-nums text-amber-700">{c.cancelled || <span className="text-slate-300">0</span>}</td>
                                            <td className="whitespace-nowrap px-2 py-2 text-right font-semibold">{formatDH(c.total)}</td>
                                            <td className={`px-2 py-2 text-right ${rateCls(c.confirmation_rate)}`}>{c.confirmation_rate} %</td>
                                            <td className={`px-2 py-2 text-right ${rateCls(c.delivery_rate)}`}>{c.delivery_rate} %</td>
                                            <td className={`px-2 py-2 text-right ${rateCls(c.return_rate, true)}`}>{c.return_rate} %</td>
                                            <td className="whitespace-nowrap px-2 py-2 text-xs text-slate-500">{formatDate(c.last_order_at)}</td>
                                            <td className="px-3 py-2">
                                                <SegmentBadges keys={c.segments} segments={segments} />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <div className="divide-y divide-slate-100 md:hidden">
                            {rows.map((c) => (
                                <Link key={c.key} to={`/clients/${c.key}`} className="block px-4 py-3">
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="min-w-0">
                                            <div className="flex flex-wrap items-center gap-1.5 font-semibold text-slate-800">
                                                {c.name} <BlockedClientAlert block={c.blocked} compact />
                                            </div>
                                            <div className="text-xs text-slate-400">{[c.phone, c.city].filter(Boolean).join(' · ')}</div>
                                        </div>
                                        <div className="text-right text-sm font-bold text-slate-900">{formatDH(c.total)}</div>
                                    </div>
                                    <div className="mt-1.5 flex flex-wrap gap-x-3 text-xs text-slate-500">
                                        <span>{c.orders} cmd</span>
                                        <span className="text-emerald-700">{c.delivered} livrée(s)</span>
                                        <span className={rateCls(c.return_rate, true)}>{c.return_rate} % retour</span>
                                    </div>
                                    <div className="mt-1.5">
                                        <SegmentBadges keys={c.segments} segments={segments} />
                                    </div>
                                </Link>
                            ))}
                        </div>
                        {data.meta.last_page > 1 ? (
                            <div className="flex items-center justify-between border-t border-slate-100 px-4 py-2.5 text-xs text-slate-500">
                                <span>
                                    {data.meta.total} client(s) · page {data.meta.current_page}/{data.meta.last_page}
                                </span>
                                <div className="flex gap-1">
                                    <Button size="sm" variant="ghost" disabled={page <= 1} onClick={() => setPage((p) => p - 1)} aria-label="Page précédente">
                                        <ChevronLeft className="h-4 w-4" />
                                    </Button>
                                    <Button size="sm" variant="ghost" disabled={page >= data.meta.last_page} onClick={() => setPage((p) => p + 1)} aria-label="Page suivante">
                                        <ChevronRight className="h-4 w-4" />
                                    </Button>
                                </div>
                            </div>
                        ) : null}
                    </>
                )}
            </Card>

            <Drawer
                open={!!groupForm}
                onClose={() => setGroupForm(null)}
                title={groupForm?.id ? 'Modifier le groupe' : 'Nouveau groupe'}
                footer={
                    <Button
                        className="w-full"
                        disabled={!groupForm?.name?.trim()}
                        onClick={() =>
                            run(() => (groupForm.id ? api.put(`/client-groups/${groupForm.id}`, groupForm) : api.post('/client-groups', groupForm))).then((d) => {
                                if (d) {
                                    setGroupForm(null);
                                    if (d.data?.id) setGroupId(String(d.data.id));
                                }
                            })
                        }
                    >
                        <Users className="h-4 w-4" /> Enregistrer
                    </Button>
                }
            >
                {groupForm ? (
                    <div className="space-y-3">
                        <Field label="Nom">
                            <Input value={groupForm.name} onChange={(e) => setGroupForm({ ...groupForm, name: e.target.value })} placeholder="Ex. VIP, Grossistes…" />
                        </Field>
                        <Field label="Couleur">
                            <Input type="color" value={groupForm.color || '#2563eb'} onChange={(e) => setGroupForm({ ...groupForm, color: e.target.value })} className="h-10 w-20 p-1" />
                        </Field>
                        <Field label="Description">
                            <Input value={groupForm.description || ''} onChange={(e) => setGroupForm({ ...groupForm, description: e.target.value })} />
                        </Field>
                    </div>
                ) : null}
            </Drawer>
        </div>
    );
}
