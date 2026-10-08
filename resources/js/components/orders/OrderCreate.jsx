import { useEffect, useMemo, useState } from 'react';
import { Trash2 } from 'lucide-react';
import api, { errorMessage } from '../../lib/api';
import { formatDH } from '../../lib/format';
import { useAuth } from '../../contexts/AuthContext';
import { useMeta } from '../../context/MetaContext';
import { Alert, Button, Drawer, Field, Input, Select, Textarea } from '../ui';
import ProductPicker, { QtyStepper } from '../products/ProductPicker';
import ProductThumb from '../products/ProductThumb';
import { BlockedClientAlert } from '../clients/ClientBadges';

function money(lines, fees, discountKind, discountValue, shipping) {
    const subtotal = lines.reduce((sum, line) => sum + Number(line.price) * Number(line.quantity), 0);
    const raw = Number(discountValue) || 0;
    const discount = discountKind === 'percent' ? Math.round(subtotal * Math.min(raw, 100)) / 100 : Math.min(raw, subtotal);
    const feeTotal = fees.reduce((sum, fee) => sum + (Number(fee.amount) || 0), 0);
    const ship = Math.max(0, Number(shipping) || 0);
    const total = Math.max(0, subtotal - discount + ship + feeTotal);
    return {
        subtotal: Math.round(subtotal * 100) / 100,
        discount: Math.round(discount * 100) / 100,
        fees: Math.round(feeTotal * 100) / 100,
        shipping: Math.round(ship * 100) / 100,
        total: Math.round(total * 100) / 100,
    };
}

function blank(userId) {
    return {
        customer_name: '',
        customer_phone: '',
        email: '',
        city: '',
        address: '',
        note: '',
        internal_note: '',
        commercial_user_id: userId || '',
        payment_method: 'cod',
        amount_paid: '',
        payment_label: '',
        discount_kind: 'amount',
        discount_value: '',
        shipping_price: '',
    };
}

export default function OrderCreate({ open, onClose, onSaved, order = null }) {
    const { can, user } = useAuth();
    const meta = useMeta();
    const [creationKey, setCreationKey] = useState('');
    const [form, setForm] = useState(blank(user?.id));
    const [lines, setLines] = useState([]);
    const [fees, setFees] = useState([]);
    const [clientQuery, setClientQuery] = useState('');
    const [clients, setClients] = useState([]);
    const [client, setClient] = useState(null);
    const [newClient, setNewClient] = useState(true);
    const [picker, setPicker] = useState(null);
    const [commercials, setCommercials] = useState([]);
    const [error, setError] = useState(null);
    const [warnings, setWarnings] = useState([]);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (!open) return;
        setError(null);
        setWarnings([]);
        setCreationKey(order?.creation_key || crypto.randomUUID());
        setNewClient(!order);
        setClient(null);
        setClientQuery('');
        if (order) {
            setForm({
                ...blank(user?.id),
                customer_name: order.customer_name || '',
                customer_phone: order.customer_phone || '',
                email: order.email || '',
                city: order.city || '',
                address: order.address || '',
                note: order.note || '',
                internal_note: order.internal_note || '',
                commercial_user_id: order.commercial_user_id || user?.id || '',
                payment_method: order.payment_method === 'paye' || order.payment_method === 'partial' ? 'paye' : 'cod',
                amount_paid: order.amount_paid || '',
                discount_kind: order.discount_kind || 'amount',
                discount_value: order.discount_value || '',
                shipping_price: order.shipping_price || '',
            });
            setLines((order.line_items || []).filter((line) => line.local_variant_id && !line.fee).map((line) => ({
                variant_id: line.local_variant_id,
                title: line.title,
                variant_title: line.variant_title,
                sku: line.sku,
                price: Number(line.price),
                catalog_price: Number(line.catalog_price ?? line.price),
                quantity: Number(line.quantity) || 1,
                image: line.image,
                inventory_tracked: false,
                allows_oversell: true,
                inventory_quantity: null,
            })));
            setFees(order.extra_fees || []);
        } else {
            setForm(blank(user?.id));
            setLines([]);
            setFees([]);
        }
        api.get('/orders/commercials').then(({ data }) => setCommercials(data.data || [])).catch(() => setCommercials([]));
    }, [open, order, user?.id]);

    useEffect(() => {
        if (!open || newClient) return undefined;
        const t = setTimeout(() => {
            api.get('/clients', { params: { search: clientQuery, per_page: 8 } })
                .then(({ data }) => setClients(data.data || []))
                .catch(() => setClients([]));
        }, 250);
        return () => clearTimeout(t);
    }, [clientQuery, open, newClient]);

    const totals = useMemo(
        () => money(lines, fees, form.discount_kind, form.discount_value, form.shipping_price),
        [lines, fees, form.discount_kind, form.discount_value, form.shipping_price],
    );
    const paid = form.payment_method === 'cod' ? 0 : (form.amount_paid === '' ? totals.total : Math.min(Number(form.amount_paid) || 0, totals.total));
    const due = Math.max(0, Math.round((totals.total - paid) * 100) / 100);

    function set(key, value) {
        setForm((prev) => ({ ...prev, [key]: value }));
    }

    async function pickClient(row) {
        setClientQuery('');
        setClients([]);
        try {
            const { data } = await api.get(`/clients/${row.key}`);
            const address = (data.addresses || [])[0] || '';
            setClient(data.client);
            setForm((prev) => ({
                ...prev,
                customer_name: data.client.name || '',
                customer_phone: data.client.phone || '',
                email: data.client.email || '',
                city: data.client.city || '',
                address,
            }));
        } catch (e) {
            setError(errorMessage(e));
        }
    }

    function addLine(variant, quantity, price, replaceIndex) {
        const tracked = Boolean(variant.inventory_tracked);
        const stock = Number(variant.inventory_quantity || 0);
        if (tracked && !variant.allows_oversell && quantity > stock) {
            setError('Stock insuffisant');
            throw { response: { data: { message: 'Stock insuffisant' } } };
        }
        const line = {
            variant_id: variant.id,
            title: variant.title,
            variant_title: variant.variant_title,
            sku: variant.sku,
            price: price != null ? Number(price) : Number(variant.price),
            catalog_price: Number(variant.price),
            quantity,
            image: variant.image,
            inventory_tracked: tracked,
            allows_oversell: variant.allows_oversell,
            inventory_quantity: variant.inventory_quantity,
        };
        setLines((prev) => {
            const next = [...prev];
            if (replaceIndex != null) next[replaceIndex] = line;
            else next.push(line);
            return next;
        });
        setError(null);
    }

    async function submit(draft) {
        setBusy(true);
        setError(null);
        try {
            const payload = {
                creation_key: creationKey,
                draft,
                customer_name: form.customer_name,
                customer_phone: form.customer_phone,
                email: form.email || null,
                city: form.city || null,
                address: form.address || null,
                lines: lines.map((line) => ({
                    variant_id: line.variant_id,
                    quantity: line.quantity,
                    price: can('orders.edit_prices') ? line.price : undefined,
                })),
                fees: fees.filter((fee) => fee.label && Number(fee.amount) > 0),
                discount_kind: can('orders.discount') ? form.discount_kind : null,
                discount_value: can('orders.discount') ? Number(form.discount_value) || 0 : 0,
                shipping_price: Number(form.shipping_price) || 0,
                payment_method: form.payment_method,
                amount_paid: form.payment_method === 'paye' ? paid : 0,
                payment_label: form.payment_label || null,
                note: form.note || null,
                internal_note: form.internal_note || null,
                commercial_user_id: form.commercial_user_id || user?.id,
            };
            const { data } = await api.post('/orders/flow', payload);
            setWarnings(data.warnings || []);
            onSaved?.(data.data, data.notice || data.message);
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy(false);
        }
    }

    const blocked = client?.blocked?.active ? client.blocked : null;

    return (
        <>
            <Drawer
                open={open}
                onClose={onClose}
                full
                title="Nouvelle commande"
                footer={
                    <div className="flex flex-wrap justify-end gap-2">
                        <Button variant="secondary" disabled={busy || lines.length === 0} onClick={() => submit(true)}>Enregistrer comme brouillon</Button>
                        <Button disabled={busy || lines.length === 0} onClick={() => submit(false)}>{busy ? 'Création en cours…' : 'Créer la commande'}</Button>
                    </div>
                }
            >
                <div className="space-y-6">
                    <Alert>{error}</Alert>
                    {warnings.length ? <div className="rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-800">{warnings.join(' · ')}</div> : null}
                    <BlockedClientAlert block={blocked} />

                    <section className="space-y-3">
                        <div className="flex items-center justify-between">
                            <h3 className="text-sm font-bold text-slate-900">Client</h3>
                            <button type="button" className="text-xs font-semibold text-blue-700" onClick={() => { setNewClient((v) => !v); setClient(null); }}>
                                {newClient ? 'Rechercher un client' : 'Nouveau client'}
                            </button>
                        </div>
                        {newClient ? null : (
                            <div className="relative">
                                <Input value={clientQuery} onChange={(e) => setClientQuery(e.target.value)} placeholder="Rechercher un client par téléphone ou nom" />
                                {clients.length ? (
                                    <ul className="absolute z-10 mt-1 max-h-56 w-full overflow-auto rounded-xl border border-slate-200 bg-white shadow-lg">
                                        {clients.map((row) => (
                                            <li key={row.key}>
                                                <button type="button" className="block w-full px-3 py-2 text-left text-sm hover:bg-slate-50" onClick={() => pickClient(row)}>
                                                    <span className="font-semibold text-slate-800">{row.name}</span>
                                                    <span className="text-slate-500"> · {row.phone}</span>
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                ) : null}
                            </div>
                        )}
                        {client ? (
                            <p className="text-xs text-slate-500">
                                {client.orders} commande(s) · {client.delivered} livrée(s) · {client.returned} retour(s) · {client.cancelled} annulée(s)
                            </p>
                        ) : null}
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="Nom"><Input value={form.customer_name} onChange={(e) => set('customer_name', e.target.value)} /></Field>
                            <Field label="Téléphone"><Input value={form.customer_phone} onChange={(e) => set('customer_phone', e.target.value)} placeholder="06…, 07… ou +212…" /></Field>
                            <Field label="Email"><Input value={form.email} onChange={(e) => set('email', e.target.value)} /></Field>
                            <Field label="Ville"><Input value={form.city} onChange={(e) => set('city', e.target.value)} /></Field>
                            <Field label="Adresse" className="sm:col-span-2"><Input value={form.address} onChange={(e) => set('address', e.target.value)} /></Field>
                        </div>
                    </section>

                    <section className="space-y-3">
                        <div className="flex items-center justify-between">
                            <h3 className="text-sm font-bold text-slate-900">Produits</h3>
                            <Button size="sm" variant="secondary" onClick={() => setPicker({ mode: 'add' })}>Ajouter un produit</Button>
                        </div>
                        {lines.length === 0 ? <p className="text-sm text-slate-500">Recherche par titre, SKU ou variante dans le catalogue Shopify.</p> : null}
                        <ul className="space-y-2">
                            {lines.map((line, index) => {
                                const low = line.inventory_tracked && Number(line.inventory_quantity) < line.quantity;
                                return (
                                    <li key={`${line.variant_id}-${index}`} className="flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 p-2">
                                        <ProductThumb src={line.image} size="h-12 w-12" />
                                        <div className="min-w-0 flex-1">
                                            <div className="truncate text-sm font-semibold text-slate-800">{line.title}</div>
                                            <div className="truncate text-xs text-slate-500">{[line.variant_title, line.sku ? `SKU ${line.sku}` : null].filter(Boolean).join(' · ')}</div>
                                            <div className="text-xs font-semibold text-slate-700">{formatDH(line.price)}</div>
                                            {low ? <div className="text-xs font-bold text-amber-700">Stock insuffisant</div> : null}
                                        </div>
                                        <QtyStepper value={line.quantity} onChange={(quantity) => setLines((prev) => prev.map((item, i) => i === index ? { ...item, quantity } : item))} />
                                        {can('orders.edit_prices') ? (
                                            <Input className="w-24" type="number" min="0" step="0.01" value={line.price} aria-label="Prix" onChange={(e) => setLines((prev) => prev.map((item, i) => i === index ? { ...item, price: e.target.value } : item))} />
                                        ) : null}
                                        <Button size="sm" variant="ghost" onClick={() => setPicker({ mode: 'replace', index })}>Remplacer</Button>
                                        <Button size="sm" variant="ghost" className="text-rose-600" onClick={() => setLines((prev) => prev.filter((_, i) => i !== index))}><Trash2 className="h-3.5 w-3.5" /> Supprimer</Button>
                                    </li>
                                );
                            })}
                        </ul>
                        {can('orders.discount') ? (
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Field label="Remise">
                                    <Select value={form.discount_kind} onChange={(e) => set('discount_kind', e.target.value)} aria-label="Type de remise">
                                        <option value="amount">Montant</option>
                                        <option value="percent">Pourcentage</option>
                                    </Select>
                                </Field>
                                <Field label={form.discount_kind === 'percent' ? 'Remise %' : 'Remise DH'}>
                                    <Input type="number" min="0" step="0.01" value={form.discount_value} onChange={(e) => set('discount_value', e.target.value)} />
                                </Field>
                            </div>
                        ) : null}
                        <div className="space-y-2">
                            <div className="flex items-center justify-between">
                                <span className="text-xs font-semibold text-slate-600">Frais supplémentaires</span>
                                <button type="button" className="text-xs font-semibold text-blue-700" onClick={() => setFees((prev) => [...prev, { label: '', amount: '' }])}>Ajouter</button>
                            </div>
                            {fees.map((fee, index) => (
                                <div key={index} className="flex gap-2">
                                    <Input placeholder="Libellé" value={fee.label} onChange={(e) => setFees((prev) => prev.map((item, i) => i === index ? { ...item, label: e.target.value } : item))} />
                                    <Input className="w-28" type="number" min="0" step="0.01" placeholder="Montant" value={fee.amount} onChange={(e) => setFees((prev) => prev.map((item, i) => i === index ? { ...item, amount: e.target.value } : item))} />
                                    <Button size="sm" variant="ghost" onClick={() => setFees((prev) => prev.filter((_, i) => i !== index))}>Supprimer</Button>
                                </div>
                            ))}
                        </div>
                        <Field label="Frais de livraison"><Input type="number" min="0" step="0.01" value={form.shipping_price} onChange={(e) => set('shipping_price', e.target.value)} /></Field>
                        <dl className="grid grid-cols-2 gap-2 rounded-xl bg-slate-50 p-3 text-sm">
                            <dt>Sous-total</dt><dd className="text-right font-semibold">{formatDH(totals.subtotal)}</dd>
                            <dt>Remise</dt><dd className="text-right font-semibold">{formatDH(totals.discount)}</dd>
                            <dt>Livraison</dt><dd className="text-right font-semibold">{formatDH(totals.shipping)}</dd>
                            <dt>Frais</dt><dd className="text-right font-semibold">{formatDH(totals.fees)}</dd>
                            <dt>Total final</dt><dd className="text-right font-bold">{formatDH(totals.total)}</dd>
                            <dt className="col-span-2 border-t border-slate-200 pt-2 font-bold text-slate-900">À encaisser : {formatDH(due)}</dt>
                        </dl>
                    </section>

                    <section className="grid gap-3 sm:grid-cols-2">
                        <Field label="Paiement">
                            <Select value={form.payment_method} onChange={(e) => set('payment_method', e.target.value)} aria-label="Paiement">
                                {(meta.paymentMethods || []).map((method) => (
                                    <option key={method.value} value={method.value}>{method.value === 'cod' ? 'COD / paiement à la livraison' : method.label}</option>
                                ))}
                            </Select>
                        </Field>
                        {form.payment_method === 'paye' ? (
                            <>
                                <Field label="Montant déjà payé"><Input type="number" min="0" step="0.01" value={form.amount_paid} onChange={(e) => set('amount_paid', e.target.value)} /></Field>
                                <Field label="Moyen de paiement"><Input value={form.payment_label} onChange={(e) => set('payment_label', e.target.value)} placeholder="Carte, virement…" /></Field>
                            </>
                        ) : null}
                        <Field label="Note client" className="sm:col-span-2"><Textarea rows={2} value={form.note} onChange={(e) => set('note', e.target.value)} /></Field>
                        <Field label="Note interne" className="sm:col-span-2"><Textarea rows={2} value={form.internal_note} onChange={(e) => set('internal_note', e.target.value)} /></Field>
                        <Field label="Commercial responsable">
                            <Select value={form.commercial_user_id} onChange={(e) => set('commercial_user_id', e.target.value)} aria-label="Commercial responsable">
                                {commercials.map((person) => (
                                    <option key={person.id} value={person.id}>{person.name}</option>
                                ))}
                            </Select>
                        </Field>
                    </section>
                </div>
            </Drawer>
            <ProductPicker
                open={Boolean(picker)}
                mode={picker?.mode || 'add'}
                replacing={picker?.mode === 'replace' ? lines[picker.index] : null}
                onClose={() => setPicker(null)}
                onSubmit={({ variant, quantity, price }) => addLine(variant, quantity, price, picker?.mode === 'replace' ? picker.index : null)}
            />
        </>
    );
}
