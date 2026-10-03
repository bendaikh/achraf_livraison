import { useEffect, useState } from 'react';
import { AlertTriangle, Pencil, Plus, Replace, Trash2 } from 'lucide-react';
import api, { errorMessage } from '../../lib/api';
import { formatDH } from '../../lib/format';
import { useAuth } from '../../contexts/AuthContext';
import { Alert, Button, Input } from '../ui';
import ProductThumb, { StockBadge } from '../products/ProductThumb';
import ProductPicker, { QtyStepper } from '../products/ProductPicker';

/**
 * Order lines with catalog photos/stock + internal edition (add / qty / price / remove / replace).
 * Changes stay inside Lav'Fast Flow (never pushed to Shopify) and are historised server-side.
 * Works from an OrderResource payload (`order`) and reports the updated order via onChanged.
 */
export default function OrderItemsEditor({ order, onChanged, compact = false }) {
    const { can } = useAuth();
    const editable = can('orders.edit_items');
    const [busyKey, setBusyKey] = useState(null);
    const [error, setError] = useState(null);
    const [warning, setWarning] = useState(null);
    const [picker, setPicker] = useState(null); // {mode:'add'} | {mode:'replace', line}
    const [priceEdit, setPriceEdit] = useState(null); // {key, value}

    useEffect(() => {
        setError(null);
    }, [order?.id]);

    async function run(key, fn) {
        setBusyKey(key);
        setError(null);
        setWarning(null);
        try {
            const { data } = await fn();
            setWarning(data.warning || null);
            onChanged?.(data.data);
            return data;
        } catch (e) {
            setError(errorMessage(e));
            throw e;
        } finally {
            setBusyKey(null);
        }
    }

    const lines = order?.line_items || [];
    const base = `/orders/${order.id}/items`;

    function remove(line) {
        const last = lines.length === 1;
        const msg = last
            ? 'C’est le dernier produit : la commande sera vide. Supprimer quand même ?'
            : `Supprimer « ${line.title} » de la commande ?`;
        if (!window.confirm(msg)) return;
        run(line.key, () => api.delete(`${base}/${encodeURIComponent(line.key)}`)).catch(() => {});
    }

    return (
        <div className="space-y-2">
            {order.items_edited_at ? (
                <p className="rounded-xl bg-violet-50 px-3 py-1.5 text-[11px] font-semibold text-violet-700">
                    Produits modifiés dans Lav'Fast Flow — modification interne, non envoyée à Shopify.
                </p>
            ) : null}
            <Alert>{error}</Alert>
            {warning ? (
                <p className="flex items-start gap-2 rounded-xl bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800">
                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" /> {warning}
                </p>
            ) : null}
            {lines.length === 0 ? (
                <p className="rounded-xl bg-slate-50 px-4 py-3 text-sm font-medium text-slate-500">Aucun produit (commande vide).</p>
            ) : (
                <ul className="divide-y divide-slate-100 rounded-xl border border-slate-100">
                    {lines.map((line) => {
                        const busy = busyKey === line.key;
                        const total = Number(line.price || 0) * Number(line.quantity || 0);
                        return (
                            <li key={line.key} className={`flex flex-wrap items-center gap-3 px-3 py-2.5 ${busy ? 'opacity-60' : ''}`}>
                                <ProductThumb src={line.image_url} size={compact ? 'h-10 w-10' : 'h-12 w-12'} />
                                <div className="min-w-0 flex-1">
                                    <div className="truncate text-sm font-semibold text-slate-800">{line.title || 'Produit'}</div>
                                    <div className="truncate text-xs text-slate-500">
                                        {[line.variant_title && line.variant_title !== 'Default Title' ? line.variant_title : null, line.sku ? `SKU ${line.sku}` : null]
                                            .filter(Boolean)
                                            .join(' · ') || '—'}
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <StockBadge item={line} />
                                        {line.source === 'lavfast' ? <span className="text-[10px] font-semibold uppercase text-violet-600">Ajouté</span> : null}
                                    </div>
                                </div>
                                <div className="flex items-center gap-2">
                                    {editable ? (
                                        <QtyStepper
                                            value={Number(line.quantity || 1)}
                                            disabled={busy}
                                            onChange={(v) => run(line.key, () => api.put(`${base}/${encodeURIComponent(line.key)}`, { quantity: v })).catch(() => {})}
                                        />
                                    ) : (
                                        <span className="text-xs font-semibold text-slate-500">× {line.quantity}</span>
                                    )}
                                    <div className="w-24 text-right">
                                        {priceEdit?.key === line.key ? (
                                            <form
                                                onSubmit={(e) => {
                                                    e.preventDefault();
                                                    run(line.key, () => api.put(`${base}/${encodeURIComponent(line.key)}`, { price: Number(priceEdit.value) }))
                                                        .then(() => setPriceEdit(null))
                                                        .catch(() => {});
                                                }}
                                            >
                                                <Input autoFocus type="number" min="0" step="0.01" value={priceEdit.value} onChange={(e) => setPriceEdit({ ...priceEdit, value: e.target.value })} className="h-8 px-2 text-right" aria-label="Prix unitaire" />
                                            </form>
                                        ) : (
                                            <>
                                                <div className="text-sm font-bold text-slate-800">{formatDH(total)}</div>
                                                <div className="text-[11px] text-slate-400">
                                                    {formatDH(line.price)} / u
                                                    {can('orders.edit_prices') ? (
                                                        <button type="button" className="ml-1 text-blue-600" aria-label="Modifier le prix" onClick={() => setPriceEdit({ key: line.key, value: line.price })}>
                                                            <Pencil className="inline h-3 w-3" />
                                                        </button>
                                                    ) : null}
                                                </div>
                                            </>
                                        )}
                                    </div>
                                </div>
                                {editable ? (
                                    <div className="flex w-full justify-end gap-1 sm:w-auto">
                                        <Button size="sm" variant="ghost" disabled={busy} onClick={() => setPicker({ mode: 'replace', line })} title="Remplacer le produit">
                                            <Replace className="h-3.5 w-3.5" /> <span className={compact ? 'sr-only' : ''}>Remplacer</span>
                                        </Button>
                                        <Button size="sm" variant="ghost" disabled={busy} onClick={() => remove(line)} title="Supprimer de la commande" className="text-rose-600 hover:bg-rose-50">
                                            <Trash2 className="h-3.5 w-3.5" /> <span className={compact ? 'sr-only' : ''}>Supprimer</span>
                                        </Button>
                                    </div>
                                ) : null}
                            </li>
                        );
                    })}
                </ul>
            )}
            {editable ? (
                <Button size="sm" variant="secondary" onClick={() => setPicker({ mode: 'add' })}>
                    <Plus className="h-3.5 w-3.5" /> Ajouter un produit
                </Button>
            ) : null}
            <div className="space-y-1 rounded-xl bg-slate-50 px-3 py-2 text-sm">
                <div className="flex justify-between text-slate-600">
                    <span>Sous-total produits</span>
                    <span>{formatDH(order.items_subtotal)}</span>
                </div>
                {order.shipping_price !== null && order.shipping_price !== undefined ? (
                    <div className="flex justify-between text-slate-600">
                        <span>Livraison</span>
                        <span>{formatDH(order.shipping_price)}</span>
                    </div>
                ) : null}
                <div className="flex justify-between font-bold text-slate-900">
                    <span>Total commande</span>
                    <span>{formatDH(order.amount)}</span>
                </div>
            </div>

            <ProductPicker
                open={!!picker}
                mode={picker?.mode}
                replacing={picker?.mode === 'replace' ? picker.line : null}
                onClose={() => setPicker(null)}
                onSubmit={({ variant, quantity, price }) =>
                    picker.mode === 'replace'
                        ? run(picker.line.key, () => api.post(`${base}/${encodeURIComponent(picker.line.key)}/replace`, { variant_id: variant.id, quantity, price }))
                        : run('add', () => api.post(base, { variant_id: variant.id, quantity, price }))
                }
            />
        </div>
    );
}
