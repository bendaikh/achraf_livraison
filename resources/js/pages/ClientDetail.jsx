import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Ban, MessageCircle, Phone, ShieldCheck, StickyNote } from 'lucide-react';
import api, { errorMessage } from '../lib/api';
import { formatDateTime, formatDH } from '../lib/format';
import { useAuth } from '../contexts/AuthContext';
import { Alert, Button, Card, Drawer, EmptyState, Field, Select, Spinner, Textarea } from '../components/ui';
import { BlockedClientAlert, SegmentBadges } from '../components/clients/ClientBadges';

function Kpi({ label, value, tone = 'text-slate-900', sub }) {
    return (
        <div className="rounded-2xl border border-slate-200/80 bg-white px-3 py-2.5 shadow-sm">
            <div className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{label}</div>
            <div className={`text-lg font-bold ${tone}`}>{value}</div>
            {sub ? <div className="text-[11px] text-slate-400">{sub}</div> : null}
        </div>
    );
}

function Pill({ color, children }) {
    return (
        <span className="rounded-md px-1.5 py-0.5 text-[11px] font-semibold" style={{ background: `${color}18`, color }}>
            {children}
        </span>
    );
}

/** T9 — Fiche client: identity, KPIs, orders, returns/exchanges, calls, WhatsApp, notes, blocks, groups. */
export default function ClientDetail() {
    const { key } = useParams();
    const navigate = useNavigate();
    const { can } = useAuth();
    const [d, setD] = useState(null);
    const [segments, setSegments] = useState([]);
    const [reasons, setReasons] = useState([]);
    const [error, setError] = useState(null);
    const [msg, setMsg] = useState(null);
    const [blocking, setBlocking] = useState(null);
    const [note, setNote] = useState('');

    const load = useCallback(async () => {
        try {
            const [{ data }, { data: s }] = await Promise.all([api.get(`/clients/${key}`), api.get('/clients/summary')]);
            setD(data);
            setSegments(s.segments);
            setReasons(s.block_reasons);
        } catch (e) {
            setError(errorMessage(e));
        }
    }, [key]);
    useEffect(() => {
        load();
    }, [load]);

    async function run(fn) {
        setError(null);
        try {
            const { data } = await fn();
            setMsg(data.message);
            await load();
            return true;
        } catch (e) {
            setError(errorMessage(e));
            return false;
        }
    }

    if (!d) return error ? <Alert>{error}</Alert> : <Spinner />;
    const c = d.client;
    const returns = d.orders.filter((o) => o.delivery_category === 'retour');
    const wa = (c.phone || '').replace(/\D/g, '');

    return (
        <div className="space-y-4">
            <button type="button" onClick={() => navigate(-1)} className="inline-flex items-center gap-1 text-sm font-semibold text-slate-500 hover:text-slate-800">
                <ArrowLeft className="h-4 w-4" /> Clients
            </button>
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0">
                    <h1 className="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">{c.name}</h1>
                    <p className="mt-1 text-sm text-slate-500">{[c.phone, c.email, c.city].filter(Boolean).join(' · ')}</p>
                    <div className="mt-2 flex flex-wrap items-center gap-1.5">
                        <SegmentBadges keys={c.segments} segments={segments} />
                        {d.groups.map((g) => (
                            <Pill key={g.id} color={g.color}>
                                {g.name}
                            </Pill>
                        ))}
                    </div>
                </div>
                <div className="flex flex-wrap gap-2">
                    <a href={`tel:${c.phone}`} className="inline-flex h-9 items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        <Phone className="h-4 w-4" /> Appeler
                    </a>
                    <Link to={`/whatsapp?search=${encodeURIComponent(c.phone || '')}`} className="inline-flex h-9 items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-emerald-700 hover:bg-slate-50">
                        <MessageCircle className="h-4 w-4" /> WhatsApp
                    </Link>
                    {can('clients.block') ? (
                        c.blocked ? (
                            <Button variant="secondary" onClick={() => setBlocking({ mode: 'unblock', reason: '' })}>
                                <ShieldCheck className="h-4 w-4" /> Débloquer
                            </Button>
                        ) : (
                            <Button variant="danger" onClick={() => setBlocking({ mode: 'block', reason: reasons[0] || '', comment: '' })}>
                                <Ban className="h-4 w-4" /> Bloquer
                            </Button>
                        )
                    ) : null}
                </div>
            </div>
            <Alert type="success">{msg}</Alert>
            <Alert>{error}</Alert>
            <BlockedClientAlert block={c.blocked} />

            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-7">
                <Kpi label="Commandes" value={c.orders} sub={`depuis ${formatDateTime(c.first_order_at).slice(0, 10)}`} />
                <Kpi label="Montant commandé" value={formatDH(c.total)} sub={`${formatDH(c.total_delivered)} livré`} />
                <Kpi label="Livrées" value={c.delivered} tone="text-emerald-700" />
                <Kpi label="Retours / échanges" value={c.returned} tone={c.returned ? 'text-rose-600' : 'text-slate-900'} />
                <Kpi label="Taux confirmation" value={`${c.confirmation_rate} %`} />
                <Kpi label="Taux livraison" value={`${c.delivery_rate} %`} />
                <Kpi label="Taux retour" value={`${c.return_rate} %`} tone={c.return_rate >= 30 ? 'text-rose-600' : 'text-slate-900'} />
            </div>

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Card title="Commandes" subtitle="Confirmations et livraisons" bodyClassName="p-0">
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-left text-sm">
                                <thead>
                                    <tr className="border-b border-slate-100 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                                        <th className="px-4 py-2">Commande</th>
                                        <th className="px-2 py-2">Date</th>
                                        <th className="px-2 py-2">Produit</th>
                                        <th className="px-2 py-2">Confirmation</th>
                                        <th className="px-2 py-2">Livraison</th>
                                        <th className="px-4 py-2 text-right">Montant</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {d.orders.map((o) => (
                                        <tr key={o.id} className="border-b border-slate-50 last:border-0">
                                            <td className="whitespace-nowrap px-4 py-2">
                                                <Link to={`/commandes/${o.id}`} className="font-semibold text-blue-700 hover:underline">
                                                    {o.reference}
                                                </Link>
                                            </td>
                                            <td className="whitespace-nowrap px-2 py-2 text-xs text-slate-500">{formatDateTime(o.date)}</td>
                                            <td className="max-w-[14rem] truncate px-2 py-2 text-slate-600">{o.product_name || '—'}</td>
                                            <td className="px-2 py-2">
                                                <Pill color={o.confirmation_color || '#64748b'}>{o.confirmation_label}</Pill>
                                            </td>
                                            <td className="px-2 py-2">
                                                <Pill color={o.delivery_color}>{o.delivery_label}</Pill>
                                            </td>
                                            <td className="whitespace-nowrap px-4 py-2 text-right font-semibold">{formatDH(o.amount)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </Card>
                    <Card title="Retours / échanges" bodyClassName="p-3">
                        {returns.length ? (
                            <ul className="space-y-1.5 text-sm">
                                {returns.map((o) => (
                                    <li key={o.id} className="flex items-center justify-between">
                                        <Link to={`/commandes/${o.id}`} className="font-semibold text-blue-700 hover:underline">
                                            {o.reference}
                                        </Link>
                                        <Pill color={o.delivery_color}>{o.delivery_label}</Pill>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-sm text-slate-400">Aucun retour ni échange.</p>
                        )}
                    </Card>
                    <Card title="Historique des appels" bodyClassName="p-3">
                        {d.calls.length ? (
                            <ul className="space-y-2 text-sm">
                                {d.calls.map((cl) => (
                                    <li key={cl.id} className="flex flex-wrap items-baseline justify-between gap-2 border-b border-slate-50 pb-1.5 last:border-0">
                                        <span>
                                            <span className="font-semibold text-slate-800">{cl.result_label}</span> <span className="text-xs text-slate-400">({cl.channel_label})</span>
                                            {cl.note ? <span className="text-slate-500"> — {cl.note}</span> : null}
                                        </span>
                                        <span className="text-xs text-slate-400">
                                            {cl.order_reference} · {cl.user_name} · {formatDateTime(cl.called_at)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-sm text-slate-400">Aucun appel enregistré.</p>
                        )}
                    </Card>
                </div>
                <div className="space-y-4">
                    <Card title="Adresses" bodyClassName="p-3">
                        {d.addresses.length ? (
                            <ul className="space-y-1 text-sm text-slate-700">
                                {d.addresses.map((a) => (
                                    <li key={a}>{a}</li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-sm text-slate-400">Aucune adresse.</p>
                        )}
                    </Card>
                    <Card title="Conversations WhatsApp" bodyClassName="p-3">
                        {d.conversations.length ? (
                            <ul className="space-y-2 text-sm">
                                {d.conversations.map((cv) => (
                                    <li key={cv.id}>
                                        <Link to={`/whatsapp?conversation=${cv.id}`} className="font-semibold text-emerald-700 hover:underline">
                                            {cv.name || c.phone}
                                        </Link>
                                        <div className="truncate text-xs text-slate-500">{cv.preview}</div>
                                        <div className="text-[11px] text-slate-400">{formatDateTime(cv.last_message_at)}</div>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-sm text-slate-400">Aucune conversation {wa ? 'liée à ce numéro' : ''}.</p>
                        )}
                    </Card>
                    <Card title="Notes internes" bodyClassName="p-3">
                        <div className="space-y-2">
                            <Textarea value={note} onChange={(e) => setNote(e.target.value)} placeholder="Ajouter une note visible par l’équipe…" aria-label="Note interne" />
                            <Button size="sm" disabled={!note.trim()} onClick={() => run(() => api.post(`/clients/${key}/notes`, { body: note })).then((ok) => ok && setNote(''))}>
                                <StickyNote className="h-3.5 w-3.5" /> Ajouter la note
                            </Button>
                            {d.notes.map((n) => (
                                <div key={n.id} className="rounded-xl bg-amber-50/70 px-3 py-2 text-sm text-slate-700">
                                    <div className="whitespace-pre-line">{n.body}</div>
                                    <div className="mt-1 text-[11px] text-slate-400">
                                        {n.user_name} · {formatDateTime(n.created_at)}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </Card>
                    <Card title="Historique des blocages" bodyClassName="p-3">
                        {d.blocks.length ? (
                            <ul className="space-y-2 text-sm">
                                {d.blocks.map((b) => (
                                    <li key={b.id} className="rounded-xl border border-slate-100 px-3 py-2">
                                        <div className="font-semibold text-slate-800">
                                            {b.reason} {b.active ? <span className="text-xs font-bold text-rose-600">(actif)</span> : null}
                                        </div>
                                        {b.comment ? <div className="text-xs text-slate-500">{b.comment}</div> : null}
                                        <div className="text-[11px] text-slate-400">
                                            Bloqué par {b.blocked_by_name} le {formatDateTime(b.blocked_at)}
                                            {b.unblocked_at ? ` · débloqué par ${b.unblocked_by_name} le ${formatDateTime(b.unblocked_at)}${b.unblock_reason ? ` (${b.unblock_reason})` : ''}` : ''}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <EmptyState>Jamais bloqué.</EmptyState>
                        )}
                    </Card>
                </div>
            </div>

            <Drawer
                open={!!blocking}
                onClose={() => setBlocking(null)}
                title={blocking?.mode === 'unblock' ? 'Débloquer le client' : 'Bloquer le client'}
                footer={
                    <Button
                        className="w-full"
                        variant={blocking?.mode === 'unblock' ? 'primary' : 'danger'}
                        disabled={blocking?.mode === 'block' && !blocking?.reason}
                        onClick={() =>
                            run(() => api.post(`/clients/${key}/${blocking.mode}`, blocking.mode === 'block' ? { reason: blocking.reason, comment: blocking.comment } : { reason: blocking.reason })).then((ok) => ok && setBlocking(null))
                        }
                    >
                        {blocking?.mode === 'unblock' ? 'Débloquer' : 'Bloquer ce client'}
                    </Button>
                }
            >
                {blocking ? (
                    <div className="space-y-3">
                        {blocking.mode === 'block' ? (
                            <>
                                <p className="text-sm text-slate-500">Les prochaines commandes de ce numéro afficheront une alerte dans Commandes et Confirmation. Les commandes existantes ne sont pas modifiées.</p>
                                <Field label="Motif">
                                    <Select value={blocking.reason} onChange={(e) => setBlocking({ ...blocking, reason: e.target.value })}>
                                        {reasons.map((r) => (
                                            <option key={r}>{r}</option>
                                        ))}
                                    </Select>
                                </Field>
                                <Field label="Commentaire">
                                    <Textarea value={blocking.comment} onChange={(e) => setBlocking({ ...blocking, comment: e.target.value })} placeholder="Détails (facultatif)" />
                                </Field>
                            </>
                        ) : (
                            <Field label="Motif du déblocage (facultatif)">
                                <Textarea value={blocking.reason} onChange={(e) => setBlocking({ ...blocking, reason: e.target.value })} />
                            </Field>
                        )}
                    </div>
                ) : null}
            </Drawer>
        </div>
    );
}
