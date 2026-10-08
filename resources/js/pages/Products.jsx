import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { AlertTriangle, ChevronLeft, ChevronRight, RefreshCw, Search, Webhook } from 'lucide-react';
import api, { errorMessage } from '../lib/api';
import { formatDH, formatDateTime } from '../lib/format';
import { useAuth } from '../contexts/AuthContext';
import { Alert, Button, Card, EmptyState, Input, PageHeader, Select, Spinner } from '../components/ui';
import { ColorBadge } from '../components/ui/Badge';
import ProductThumb, { StockBadge } from '../components/products/ProductThumb';
import SyncStatusBadge from '../components/shopify/SyncStatusBadge';

const STATUS_COLORS = { active: '#16a34a', draft: '#d97706', archived: '#64748b' };

/** Produits — catalog synced from Shopify (one row per variant: SKU / price / stock). */
export default function Products() {
    const { can } = useAuth();
    const [filters, setFilters] = useState({ q: '', status: '', stock: '', collection: '', page: 1 });
    const [search, setSearch] = useState('');
    const [result, setResult] = useState(null);
    const [status, setStatus] = useState(null);
    const [error, setError] = useState(null);
    const [msg, setMsg] = useState(null);
    const [busy, setBusy] = useState('');
    const [edit, setEdit] = useState(null);

    const load = useCallback(async () => {
        setError(null);
        try {
            const params = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '' && v !== null));
            const { data } = await api.get('/products', { params: { per_page: 25, ...params } });
            setResult(data);
        } catch (e) {
            setError(errorMessage(e));
        }
    }, [filters]);

    const loadStatus = useCallback(() => api.get('/products/status').then(({ data }) => setStatus(data)).catch(() => {}), []);

    async function saveEdit(event) {
        event.preventDefault();
        setBusy('edit');
        setError(null);
        try {
            if (edit.title !== edit.originalTitle) {
                await api.put(`/products/${edit.product_id}`, { title: edit.title });
            }
            const fields = {};
            if (String(edit.price) !== String(edit.originalPrice)) fields.price = edit.price;
            if (edit.sku !== edit.originalSku) fields.sku = edit.sku;
            if (String(edit.inventory_quantity) !== String(edit.originalStock)) fields.inventory_quantity = Number(edit.inventory_quantity);
            if (Object.keys(fields).length) {
                await api.put(`/products/variants/${edit.id}`, fields);
            }
            setMsg('Produit envoyé à Shopify.');
            setEdit(null);
            await load();
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy('');
        }
    }

    useEffect(() => {
        load();
    }, [load]);
    useEffect(() => {
        loadStatus();
    }, [loadStatus]);
    useEffect(() => {
        const t = setTimeout(() => setFilters((f) => (f.q === search ? f : { ...f, q: search, page: 1 })), 300);
        return () => clearTimeout(t);
    }, [search]);

    const set = (k, v) => setFilters((f) => ({ ...f, [k]: v, page: k === 'page' ? v : 1 }));

    async function action(kind) {
        setBusy(kind);
        setMsg(null);
        setError(null);
        try {
            const { data } = kind === 'sync' ? await api.post('/products/sync', { full: true }) : await api.post('/products/webhooks');
            setMsg([data.message, ...(data.results || []).map((r) => `${r.shop} : ${r.message}`)].join(' '));
            load();
            loadStatus();
        } catch (e) {
            const results = e?.response?.data?.results;
            setError(results?.length ? results.map((r) => `${r.shop} : ${r.message}`).join(' ') : errorMessage(e));
            loadStatus();
        } finally {
            setBusy('');
        }
    }

    const rows = result?.data || [];
    const shops = status?.shops || [];
    const missingScope = shops.find((s) => !s.has_products_scope);
    const lastSync = shops.map((s) => s.catalog_synced_at).filter(Boolean).sort().pop();

    return (
        <div className="space-y-4">
            <PageHeader
                title="Produits"
                subtitle={
                    result
                        ? `${result.counts.products} produit(s) · ${result.counts.variants} variante(s) · ${result.counts.out_of_stock} en rupture${lastSync ? ` · Dernière synchro ${formatDateTime(lastSync)}` : ''}`
                        : 'Catalogue synchronisé depuis Shopify'
                }
                actions={
                    can('products.sync') && shops.length ? (
                        <>
                            <Button variant="secondary" onClick={() => action('webhooks')} disabled={!!busy} title="Enregistrer les webhooks produits / stock dans Shopify">
                                <Webhook className="h-4 w-4" /> {busy === 'webhooks' ? 'Enregistrement…' : 'Webhooks'}
                            </Button>
                            <Button onClick={() => action('sync')} disabled={!!busy}>
                                <RefreshCw className={`h-4 w-4 ${busy === 'sync' ? 'animate-spin' : ''}`} /> {busy === 'sync' ? 'Synchronisation…' : 'Synchroniser Shopify'}
                            </Button>
                        </>
                    ) : null
                }
            />

            {status && shops.length === 0 ? (
                <Alert>
                    Aucune boutique Shopify connectée.{' '}
                    <Link to="/integrations/shopify" className="underline">
                        Connecter Shopify
                    </Link>{' '}
                    pour importer le catalogue.
                </Alert>
            ) : null}
            {missingScope ? (
                <p className="flex items-start gap-2 rounded-xl bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800 ring-1 ring-amber-200">
                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                    La boutique {missingScope.shop_name || missingScope.shop_domain} n’a pas encore autorisé l’accès aux produits (read_products, read_inventory). Ajoutez ces droits dans
                    Intégrations → Shopify puis reconnectez la boutique.
                </p>
            ) : null}
            {shops.filter((s) => s.catalog_sync_error).map((s) => (
                <Alert key={s.id}>
                    Dernière synchronisation en erreur ({s.shop_domain}) : {s.catalog_sync_error}
                </Alert>
            ))}
            <Alert type="success">{msg}</Alert>
            <Alert>{error}</Alert>

            <Card bodyClassName="p-3 sm:p-4">
                <div className="relative">
                    <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Nom, variante, SKU, code-barres…" className="pl-9" />
                </div>
                <div className="mt-2 grid grid-cols-2 gap-2 md:grid-cols-3">
                    <Select value={filters.status} onChange={(e) => set('status', e.target.value)}>
                        <option value="">Tous les statuts</option>
                        <option value="active">Actif</option>
                        <option value="draft">Brouillon</option>
                        <option value="archived">Archivé</option>
                    </Select>
                    <Select value={filters.stock} onChange={(e) => set('stock', e.target.value)}>
                        <option value="">Tout le stock</option>
                        <option value="in_stock">Avec stock</option>
                        <option value="out_of_stock">Sans stock (rupture)</option>
                    </Select>
                    <Select value={filters.collection} onChange={(e) => set('collection', e.target.value)} className="col-span-2 md:col-span-1">
                        <option value="">Toutes les collections</option>
                        {(result?.collections || []).map((c) => (
                            <option key={c} value={c}>
                                {c}
                            </option>
                        ))}
                    </Select>
                </div>
            </Card>

            {edit && can('products.edit_shopify') ? (
                <Card title="Modifier dans Shopify">
                    <form onSubmit={saveEdit} className="grid gap-3 sm:grid-cols-2">
                        <label className="text-sm font-semibold text-slate-700">
                            Titre
                            <Input className="mt-1" value={edit.title} onChange={(e) => setEdit({ ...edit, title: e.target.value })} />
                        </label>
                        <label className="text-sm font-semibold text-slate-700">
                            Prix
                            <Input className="mt-1" type="number" step="0.01" value={edit.price} onChange={(e) => setEdit({ ...edit, price: e.target.value })} />
                        </label>
                        <label className="text-sm font-semibold text-slate-700">
                            SKU
                            <Input className="mt-1" value={edit.sku || ''} onChange={(e) => setEdit({ ...edit, sku: e.target.value })} />
                        </label>
                        {can('products.edit_stock_shopify') ? (
                            <label className="text-sm font-semibold text-slate-700">
                                Stock
                                <Input className="mt-1" type="number" value={edit.inventory_quantity ?? ''} onChange={(e) => setEdit({ ...edit, inventory_quantity: e.target.value })} />
                            </label>
                        ) : null}
                        <p className="text-xs text-slate-500 sm:col-span-2">Les photos se gèrent dans Shopify. Une hausse de prix sur une ligne de commande déjà vendue n’est pas envoyée : utilisez « Remplacer produit ».</p>
                        <div className="flex gap-2">
                            <Button type="submit" disabled={busy === 'edit'}>{busy === 'edit' ? 'Envoi…' : 'Envoyer à Shopify'}</Button>
                            <Button type="button" variant="secondary" onClick={() => setEdit(null)}>Annuler</Button>
                        </div>
                    </form>
                </Card>
            ) : null}

            {!result ? (
                <Spinner />
            ) : rows.length === 0 ? (
                <Card>
                    <EmptyState>{result.counts.variants ? 'Aucun produit ne correspond aux filtres.' : 'Catalogue vide : lancez une synchronisation Shopify.'}</EmptyState>
                </Card>
            ) : (
                <>
                    <div className="space-y-2 md:hidden">
                        {rows.map((v) => (
                            <article key={v.id} className="flex gap-3 rounded-2xl border border-slate-200/80 bg-white p-3 shadow-sm">
                                <ProductThumb src={v.image} size="h-14 w-14" />
                                <div className="min-w-0 flex-1">
                                    <div className="truncate text-sm font-bold text-slate-900">{v.title}</div>
                                    <div className="truncate text-xs text-slate-500">{[v.variant_title, v.sku].filter(Boolean).join(' · ') || '—'}</div>
                                    <div className="mt-1 flex flex-wrap items-center gap-2">
                                        <span className="text-sm font-bold text-slate-800">{formatDH(v.price)}</span>
                                        <StockBadge item={v} />
                                        <ColorBadge color={STATUS_COLORS[v.status]} label={v.status_label} />
                                    </div>
                                </div>
                            </article>
                        ))}
                    </div>
                    <Card bodyClassName="p-0" className="hidden md:block">
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-left text-sm">
                                <thead>
                                    <tr className="border-b border-slate-100 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                                        <th className="px-4 py-3">Produit</th>
                                        <th className="px-3 py-3">Variante</th>
                                        <th className="px-3 py-3">SKU</th>
                                        <th className="px-3 py-3 text-right">Prix</th>
                                        <th className="px-3 py-3">Stock</th>
                                        <th className="px-3 py-3">Statut</th>
                                        <th className="px-3 py-3">Collection</th>
                                        <th className="px-3 py-3">Source</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((v) => (
                                        <tr key={v.id} className="border-b border-slate-50 last:border-0 hover:bg-slate-50/70">
                                            <td className="px-4 py-2.5">
                                                <div className="flex items-center gap-3">
                                                    <ProductThumb src={v.image} size="h-10 w-10" />
                                                    <span className="max-w-[260px] truncate font-semibold text-slate-800">{v.title}</span>
                                                    <SyncStatusBadge
                                                        status={v.shopify_sync_status}
                                                        error={v.shopify_sync_error}
                                                        onRetry={v.shopify_sync_status === 'failed' && can('products.edit_shopify') ? async () => {
                                                            setError(null);
                                                            try {
                                                                await api.post(`/products/${v.product_id}/shopify-retry`);
                                                                await load();
                                                            } catch (e) {
                                                                setError(errorMessage(e));
                                                            }
                                                        } : undefined}
                                                    />
                                                    {can('products.edit_shopify') && v.shopify_product_id ? (
                                                        <button
                                                            type="button"
                                                            className="text-xs font-semibold text-blue-600"
                                                            onClick={() => setEdit({
                                                                ...v,
                                                                originalTitle: v.title,
                                                                originalPrice: v.price,
                                                                originalSku: v.sku,
                                                                originalStock: v.inventory_quantity,
                                                            })}
                                                        >
                                                            Modifier
                                                        </button>
                                                    ) : null}
                                                </div>
                                            </td>
                                            <td className="px-3 py-2.5 text-slate-600">{v.variant_title || '—'}</td>
                                            <td className="px-3 py-2.5 font-mono text-xs text-slate-600">{v.sku || '—'}</td>
                                            <td className="whitespace-nowrap px-3 py-2.5 text-right">
                                                <div className="font-semibold text-slate-800">{formatDH(v.price)}</div>
                                                {v.compare_at_price ? <div className="text-[11px] text-slate-400 line-through">{formatDH(v.compare_at_price)}</div> : null}
                                            </td>
                                            <td className="whitespace-nowrap px-3 py-2.5">
                                                <StockBadge item={v} />
                                            </td>
                                            <td className="px-3 py-2.5">
                                                <ColorBadge color={STATUS_COLORS[v.status]} label={v.status_label} />
                                            </td>
                                            <td className="max-w-[160px] truncate px-3 py-2.5 text-slate-500">{(v.collections || []).join(', ') || '—'}</td>
                                            <td className="whitespace-nowrap px-3 py-2.5 text-xs text-slate-500">{v.source_label}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </Card>
                    {result.meta.last_page > 1 ? (
                        <div className="flex items-center justify-center gap-2 text-sm">
                            <Button size="sm" variant="secondary" disabled={filters.page <= 1} onClick={() => set('page', filters.page - 1)}>
                                <ChevronLeft className="h-4 w-4" />
                            </Button>
                            <span className="font-medium text-slate-600">
                                Page {result.meta.current_page} / {result.meta.last_page}
                            </span>
                            <Button size="sm" variant="secondary" disabled={filters.page >= result.meta.last_page} onClick={() => set('page', filters.page + 1)}>
                                <ChevronRight className="h-4 w-4" />
                            </Button>
                        </div>
                    ) : null}
                </>
            )}
        </div>
    );
}
