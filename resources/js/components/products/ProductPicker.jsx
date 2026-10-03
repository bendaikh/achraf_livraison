import { useEffect, useState } from 'react';
import { AlertTriangle, Minus, Plus, Search } from 'lucide-react';
import api, { errorMessage } from '../../lib/api';
import { formatDH } from '../../lib/format';
import { useAuth } from '../../contexts/AuthContext';
import { Alert, Button, Drawer, Field, Input, Spinner } from '../ui';
import ProductThumb, { StockBadge } from './ProductThumb';

export function QtyStepper({ value, onChange, min = 1, disabled = false }) {
    return (
        <div className="inline-flex items-center rounded-xl border border-slate-200 bg-white">
            <button type="button" aria-label="Diminuer" disabled={disabled || value <= min} onClick={() => onChange(value - 1)} className="flex h-8 w-8 items-center justify-center text-slate-600 disabled:opacity-40">
                <Minus className="h-3.5 w-3.5" />
            </button>
            <span className="min-w-8 text-center text-sm font-bold text-slate-800" aria-live="polite">
                {value}
            </span>
            <button type="button" aria-label="Augmenter" disabled={disabled} onClick={() => onChange(value + 1)} className="flex h-8 w-8 items-center justify-center text-slate-600 disabled:opacity-40">
                <Plus className="h-3.5 w-3.5" />
            </button>
        </div>
    );
}

/**
 * Catalog picker (synced Shopify products): search → variant → quantity (→ price if allowed).
 * mode = 'add' | 'replace'. onSubmit({variant, quantity, price}) must return a promise.
 */
export default function ProductPicker({ open, onClose, mode = 'add', replacing = null, onSubmit }) {
    const { can } = useAuth();
    const [q, setQ] = useState('');
    const [items, setItems] = useState(null);
    const [error, setError] = useState(null);
    const [selected, setSelected] = useState(null);
    const [qty, setQty] = useState(1);
    const [price, setPrice] = useState('');
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (!open) return;
        setSelected(null);
        setQty(replacing?.quantity || 1);
        setPrice('');
        setError(null);
        setQ('');
    }, [open, replacing]);

    useEffect(() => {
        if (!open) return undefined;
        const t = setTimeout(() => {
            api.get('/products', { params: { q, per_page: 30, status: 'active' } })
                .then(({ data }) => setItems(data.data))
                .catch((e) => setError(errorMessage(e)));
        }, 250);
        return () => clearTimeout(t);
    }, [q, open]);

    async function submit() {
        setBusy(true);
        setError(null);
        try {
            await onSubmit({ variant: selected, quantity: qty, price: price !== '' ? Number(price) : null });
            onClose();
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy(false);
        }
    }

    const outOfStock = selected && selected.inventory_tracked && Number(selected.inventory_quantity || 0) < qty;

    return (
        <Drawer
            open={open}
            onClose={onClose}
            title={mode === 'replace' ? 'Remplacer le produit' : 'Ajouter un produit'}
            footer={
                selected ? (
                    <div className="flex gap-2">
                        <Button variant="secondary" onClick={() => setSelected(null)}>
                            Retour
                        </Button>
                        <Button className="flex-1" onClick={submit} disabled={busy}>
                            {busy ? 'Enregistrement…' : mode === 'replace' ? 'Valider le remplacement' : `Ajouter · ${formatDH((price !== '' ? Number(price) : selected.price) * qty)}`}
                        </Button>
                    </div>
                ) : null
            }
        >
            {replacing ? (
                <p className="mb-3 rounded-xl bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800">
                    Remplace : <span className="font-bold">{replacing.title}{replacing.variant_title ? ` (${replacing.variant_title})` : ''}</span> × {replacing.quantity}
                </p>
            ) : null}
            <Alert>{error}</Alert>
            {!selected ? (
                <div className="space-y-3">
                    <div className="relative">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <Input autoFocus value={q} onChange={(e) => setQ(e.target.value)} placeholder="Nom du produit, variante, SKU…" className="pl-9" />
                    </div>
                    {!items ? (
                        <Spinner />
                    ) : items.length === 0 ? (
                        <p className="rounded-xl bg-slate-50 px-3 py-6 text-center text-sm text-slate-500">Aucun produit. Synchronisez le catalogue Shopify depuis la page Produits.</p>
                    ) : (
                        <ul className="space-y-1.5">
                            {items.map((v) => (
                                <li key={v.id}>
                                    <button type="button" onClick={() => setSelected(v)} className="flex w-full items-center gap-3 rounded-xl border border-slate-200 px-3 py-2 text-left transition hover:border-blue-300 hover:bg-blue-50/40">
                                        <ProductThumb src={v.image} size="h-11 w-11" />
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-sm font-semibold text-slate-800">{v.title}</span>
                                            <span className="block truncate text-xs text-slate-500">
                                                {[v.variant_title, v.sku ? `SKU ${v.sku}` : null].filter(Boolean).join(' · ') || '—'}
                                            </span>
                                            <StockBadge item={v} />
                                        </span>
                                        <span className="shrink-0 text-sm font-bold text-slate-800">{formatDH(v.price)}</span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            ) : (
                <div className="space-y-4">
                    <div className="flex items-center gap-3 rounded-xl border border-slate-200 p-3">
                        <ProductThumb src={selected.image} size="h-16 w-16" />
                        <div className="min-w-0">
                            <div className="font-bold text-slate-900">{selected.title}</div>
                            <div className="text-xs text-slate-500">{[selected.variant_title, selected.sku ? `SKU ${selected.sku}` : null].filter(Boolean).join(' · ')}</div>
                            <StockBadge item={selected} />
                            <div className="mt-0.5 text-sm font-semibold text-slate-700">{formatDH(selected.price)} (prix Shopify actuel)</div>
                        </div>
                    </div>
                    <Field label="Quantité">
                        <QtyStepper value={qty} onChange={setQty} />
                    </Field>
                    {can('orders.edit_prices') ? (
                        <Field label="Prix unitaire (optionnel)" hint="Vide = prix Shopify actuel. Toute modification de prix est historisée.">
                            <Input type="number" min="0" step="0.01" value={price} placeholder={String(selected.price)} onChange={(e) => setPrice(e.target.value)} />
                        </Field>
                    ) : null}
                    {outOfStock ? (
                        <p className="flex items-start gap-2 rounded-xl bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800">
                            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                            {Number(selected.inventory_quantity || 0) <= 0 ? 'Rupture de stock' : `Seulement ${selected.inventory_quantity} en stock`} — la commande reste possible si les
                            précommandes sont autorisées (Paramètres).
                        </p>
                    ) : null}
                </div>
            )}
        </Drawer>
    );
}
