import { useEffect, useState } from 'react';
import { ArrowDownToLine, ArrowUpFromLine, Plus, Search, Trash2 } from 'lucide-react';
import api, { errorMessage } from '../../lib/api';
import { formatDate, formatDH } from '../../lib/format';
import { Alert, Button, Drawer, Field, Input, Select, Spinner, Textarea } from '../ui';
import ProductThumb from '../products/ProductThumb';
import ProductPicker, { QtyStepper } from '../products/ProductPicker';

/** T7 — Créer un retour / échange depuis une commande livrée (auto-remplissage). */
export default function SavCreateDrawer({ open, onClose, onCreated, initialOrderId = null }) {
    const [meta, setMeta] = useState(null);
    const [q, setQ] = useState('');
    const [results, setResults] = useState(null);
    const [pre, setPre] = useState(null);
    const [form, setForm] = useState(null);
    const [picker, setPicker] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    useEffect(() => {
        if (!open) return;
        setError(null);
        setPre(null);
        setForm(null);
        setQ('');
        setResults(null);
        api.get('/sav/meta').then(({ data }) => setMeta(data));
        if (initialOrderId) choose(initialOrderId);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, initialOrderId]);

    useEffect(() => {
        if (!open || pre) return undefined;
        const t = setTimeout(() => {
            api.get('/sav/orders', { params: { q } }).then(({ data }) => setResults(data.data));
        }, 250);
        return () => clearTimeout(t);
    }, [q, open, pre]);

    async function choose(orderId) {
        try {
            const { data } = await api.get(`/sav/orders/${orderId}/prefill`);
            setPre(data);
            setForm({ type: 'retour', reason: '', comment: '', sav_note: '', driver_id: '', pickup: Object.fromEntries(data.lines.map((l) => [l.key, 0])), deliver: [] });
        } catch (e) {
            setError(errorMessage(e));
        }
    }

    async function submit() {
        setBusy(true);
        setError(null);
        try {
            const pickup = Object.entries(form.pickup)
                .filter(([, qty]) => qty > 0)
                .map(([key, quantity]) => ({ key, quantity }));
            const { data } = await api.post('/sav', {
                order_id: pre.order_id,
                type: form.type,
                reason: form.reason,
                comment: form.comment || null,
                sav_note: form.sav_note || null,
                driver_id: form.driver_id ? Number(form.driver_id) : null,
                pickup,
                deliver: form.type === 'echange' ? form.deliver.map((d) => ({ variant_id: d.variant.id, quantity: d.quantity })) : [],
            });
            onCreated?.(data);
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy(false);
        }
    }

    const set = (k, v) => setForm((f) => ({ ...f, [k]: v }));
    const pickedCount = form ? Object.values(form.pickup).reduce((a, b) => a + b, 0) : 0;

    return (
        <Drawer
            open={open}
            onClose={onClose}
            wide
            title="Nouveau retour / échange"
            footer={
                pre ? (
                    <Button className="w-full" onClick={submit} disabled={busy || !form.reason || !pickedCount || (form.type === 'echange' && !form.deliver.length)}>
                        {busy ? 'Création…' : form.driver_id ? 'Créer et affecter au livreur' : 'Créer la demande'}
                    </Button>
                ) : null
            }
        >
            <div className="space-y-4">
                <Alert>{error}</Alert>
                {!pre ? (
                    <>
                        <div className="relative">
                            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                            <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="N° commande, nom du client, téléphone…" className="pl-9" autoFocus aria-label="Rechercher une commande livrée" />
                        </div>
                        <p className="text-xs text-slate-400">Seules les commandes livrées apparaissent.</p>
                        {!results ? (
                            <Spinner />
                        ) : !results.length ? (
                            <p className="py-6 text-center text-sm text-slate-400">Aucune commande livrée trouvée.</p>
                        ) : (
                            <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200">
                                {results.map((o) => (
                                    <li key={o.id}>
                                        <button type="button" onClick={() => choose(o.id)} className="flex w-full items-center justify-between gap-3 px-3 py-2.5 text-left text-sm hover:bg-slate-50">
                                            <span className="min-w-0">
                                                <span className="font-bold text-blue-700">{o.reference}</span> <span className="font-semibold text-slate-800">{o.customer_name}</span>
                                                <span className="block truncate text-xs text-slate-400">{[o.phone, o.city, o.product_name].filter(Boolean).join(' · ')}</span>
                                            </span>
                                            <span className="shrink-0 text-right text-xs text-slate-500">
                                                {formatDH(o.amount)}
                                                <span className="block">Livrée {formatDate(o.delivered_at)}</span>
                                            </span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </>
                ) : (
                    <>
                        <div className="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm">
                            <div className="flex items-center justify-between">
                                <span className="font-bold text-slate-900">{pre.reference}</span>
                                {!initialOrderId ? (
                                    <button type="button" className="text-xs font-semibold text-blue-700" onClick={() => setPre(null)}>
                                        Changer de commande
                                    </button>
                                ) : null}
                            </div>
                            <div className="mt-1 grid grid-cols-2 gap-x-4 gap-y-1 text-xs text-slate-600">
                                <span>
                                    <b>{pre.customer_name}</b> · {pre.phone}
                                </span>
                                <span>{[pre.address, pre.city].filter(Boolean).join(', ')}</span>
                                <span>
                                    Montant : <b>{formatDH(pre.amount_paid)}</b> ({pre.payment_method === 'paye' ? 'payé' : 'COD'})
                                </span>
                                <span>
                                    Livrée par <b>{pre.original_driver?.name || '—'}</b> le {formatDate(pre.delivered_at)}
                                </span>
                            </div>
                            {pre.open_requests ? <p className="mt-1.5 text-xs font-semibold text-amber-700">{pre.open_requests} demande(s) SAV déjà en cours sur cette commande.</p> : null}
                        </div>

                        <div className="grid grid-cols-2 gap-2">
                            {(meta?.types || []).map((t) => (
                                <button key={t.value} type="button" onClick={() => set('type', t.value)} className={`rounded-xl border p-3 text-left ${form.type === t.value ? 'border-blue-400 bg-blue-50 ring-2 ring-blue-100' : 'border-slate-200 hover:bg-slate-50'}`}>
                                    <div className="text-sm font-bold text-slate-900">{t.label}</div>
                                    <div className="text-[11px] text-slate-500">{t.description}</div>
                                </button>
                            ))}
                        </div>

                        <div>
                            <h3 className="mb-1.5 flex items-center gap-1.5 text-xs font-bold uppercase tracking-wide text-slate-500">
                                <ArrowDownToLine className="h-3.5 w-3.5" /> Produit(s) à récupérer chez le client
                            </h3>
                            <div className="space-y-1.5">
                                {pre.lines.map((l) => (
                                    <div key={l.key} className="flex items-center gap-3 rounded-xl border border-slate-200 px-3 py-2">
                                        <ProductThumb src={l.image_url} alt={l.title} />
                                        <div className="min-w-0 flex-1 text-sm">
                                            <div className="truncate font-semibold text-slate-800">{l.title}</div>
                                            <div className="text-xs text-slate-400">{[l.variant_title, l.sku, `${l.quantity} commandé(s)`, formatDH(l.price)].filter(Boolean).join(' · ')}</div>
                                        </div>
                                        <QtyStepper value={form.pickup[l.key]} min={0} onChange={(v) => set('pickup', { ...form.pickup, [l.key]: Math.min(v, l.quantity) })} />
                                    </div>
                                ))}
                            </div>
                        </div>

                        {form.type === 'echange' ? (
                            <div>
                                <h3 className="mb-1.5 flex items-center gap-1.5 text-xs font-bold uppercase tracking-wide text-slate-500">
                                    <ArrowUpFromLine className="h-3.5 w-3.5" /> Nouveau produit à remettre
                                </h3>
                                <div className="space-y-1.5">
                                    {form.deliver.map((d, i) => (
                                        <div key={i} className="flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50/40 px-3 py-2">
                                            <ProductThumb src={d.variant.image_url || d.variant.image} alt={d.variant.title} />
                                            <div className="min-w-0 flex-1 text-sm">
                                                <div className="truncate font-semibold text-slate-800">{d.variant.product_title || d.variant.title}</div>
                                                <div className="text-xs text-slate-400">{[d.variant.variant_title, d.variant.sku].filter(Boolean).join(' · ')}</div>
                                            </div>
                                            <QtyStepper value={d.quantity} onChange={(v) => set('deliver', form.deliver.map((x, j) => (j === i ? { ...x, quantity: v } : x)))} />
                                            <Button size="sm" variant="ghost" aria-label="Retirer" onClick={() => set('deliver', form.deliver.filter((_, j) => j !== i))}>
                                                <Trash2 className="h-3.5 w-3.5" />
                                            </Button>
                                        </div>
                                    ))}
                                    <Button size="sm" variant="secondary" onClick={() => setPicker(true)}>
                                        <Plus className="h-3.5 w-3.5" /> Choisir dans le catalogue
                                    </Button>
                                </div>
                            </div>
                        ) : null}

                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="Motif *">
                                <Select value={form.reason} onChange={(e) => set('reason', e.target.value)}>
                                    <option value="">Choisir…</option>
                                    {(meta?.reasons || []).map((r) => (
                                        <option key={r}>{r}</option>
                                    ))}
                                </Select>
                            </Field>
                            <Field label="Affecter à un livreur" hint="Apparaît immédiatement dans son espace « Retours & échanges ».">
                                <Select value={form.driver_id} onChange={(e) => set('driver_id', e.target.value)}>
                                    <option value="">Plus tard (À attribuer)</option>
                                    {(meta?.drivers || []).map((d) => (
                                        <option key={d.id} value={d.id}>
                                            {d.name} — {formatDH(form.type === 'echange' ? d.tariff_echange : d.tariff_retour)}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                        </div>
                        <Field label={form.reason === 'Autre' ? 'Commentaire *' : 'Commentaire'}>
                            <Textarea value={form.comment} onChange={(e) => set('comment', e.target.value)} placeholder="Détail du problème" />
                        </Field>
                        <Field label="Note SAV pour le livreur">
                            <Textarea value={form.sav_note} onChange={(e) => set('sav_note', e.target.value)} placeholder="Ex. vérifier l’emballage d’origine" />
                        </Field>
                    </>
                )}
            </div>
            <ProductPicker
                open={picker}
                mode="add"
                onClose={() => setPicker(false)}
                onSubmit={async ({ variant, quantity }) => {
                    set('deliver', [...form.deliver, { variant, quantity }]);
                    setPicker(false);
                }}
            />
        </Drawer>
    );
}
