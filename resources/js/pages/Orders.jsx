import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { ChevronLeft, ChevronRight, Layers, Plus, RotateCcw, Search, X } from 'lucide-react';
import api, { errorMessage } from '../lib/api';
import { useMeta } from '../context/MetaContext';
import useUserPreference from '../hooks/useUserPreference';
import useSelection from '../hooks/useSelection';
import { Alert, Button, Card, Checkbox, EmptyState, Input, PageHeader, Select, Spinner } from '../components/ui';
import ColumnSelector from '../components/orders/ColumnSelector';
import OrderForm from '../components/orders/OrderForm';
import { DEFAULT_COLUMN_PREFS, ORDER_COLUMNS, renderCell } from '../components/orders/orderColumns';

const FILTER_KEYS = ['q', 'delivery_status_id', 'status_category', 'confirmation_status', 'driver_id', 'date_from', 'date_to'];

export default function Orders() {
    const meta = useMeta();
    const navigate = useNavigate();
    const [params, setParams] = useSearchParams();
    const [result, setResult] = useState(null);
    const [error, setError] = useState(null);
    const [creating, setCreating] = useState(false);
    const [search, setSearch] = useState(params.get('q') || '');
    const [prefs, setPrefs] = useUserPreference('orders.columns', DEFAULT_COLUMN_PREFS);
    const selection = useSelection();

    const page = Number(params.get('page') || 1);
    const filters = Object.fromEntries(FILTER_KEYS.map((k) => [k, params.get(k) || '']));

    const visibleColumns = useMemo(() => {
        const keys = Array.isArray(prefs?.desktop) && prefs.desktop.length ? prefs.desktop : DEFAULT_COLUMN_PREFS.desktop;
        return ORDER_COLUMNS.filter((c) => keys.includes(c.key));
    }, [prefs]);
    const mobileColumns = useMemo(() => {
        const keys = Array.isArray(prefs?.mobile) ? prefs.mobile : DEFAULT_COLUMN_PREFS.mobile;
        return ORDER_COLUMNS.filter((c) => keys.includes(c.key) && c.key !== 'reference');
    }, [prefs]);

    const load = useCallback(async () => {
        setError(null);
        try {
            const query = Object.fromEntries([...params.entries()].filter(([, v]) => v));
            const { data } = await api.get('/orders', { params: { per_page: 25, ...query } });
            setResult(data);
        } catch (e) {
            setError(errorMessage(e));
        }
    }, [params]);

    useEffect(() => {
        load();
    }, [load]);

    useEffect(() => {
        const t = setTimeout(() => {
            if ((params.get('q') || '') !== search) setFilter('q', search);
        }, 300);
        return () => clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    function setFilter(key, value) {
        const next = new URLSearchParams(params);
        if (value) next.set(key, value);
        else next.delete(key);
        if (key !== 'page') next.delete('page');
        setParams(next, { replace: true });
    }

    const orders = result?.data || [];
    const pageIds = orders.map((o) => o.id);
    const { all: allChecked, some: someChecked } = selection.pageState(pageIds);
    const lastPage = result?.meta?.last_page || 1;
    const activeFilters = FILTER_KEYS.some((k) => filters[k]);
    const categoryLabel = filters.status_category
        ? filters.status_category
              .split(',')
              .map((c) => meta.categoryMap[c]?.label || c)
              .join(', ')
        : null;

    return (
        <div className="space-y-4">
            <PageHeader
                title="Commandes"
                subtitle={result ? `${result.meta?.total ?? 0} commande(s)` : 'Liste des commandes'}
                actions={
                    <Button onClick={() => setCreating(true)}>
                        <Plus className="h-4 w-4" /> Nouvelle commande
                    </Button>
                }
            />

            <Card bodyClassName="p-3 sm:p-4">
                <div className="flex gap-2">
                    <div className="relative min-w-0 flex-1">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="N°, client, téléphone, ville, produit…" className="pl-9" />
                    </div>
                    <ColumnSelector prefs={{ ...DEFAULT_COLUMN_PREFS, ...prefs }} onChange={setPrefs} />
                </div>
                <div className="mt-2 grid grid-cols-2 gap-2 md:grid-cols-5">
                    <Select value={filters.delivery_status_id} onChange={(e) => setFilter('delivery_status_id', e.target.value)}>
                        <option value="">Tous les statuts</option>
                        {meta.statuses.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.name}
                            </option>
                        ))}
                    </Select>
                    <Select value={filters.confirmation_status} onChange={(e) => setFilter('confirmation_status', e.target.value)}>
                        <option value="">Toutes confirmations</option>
                        {meta.confirmationStatuses.map((c) => (
                            <option key={c.value} value={c.value}>
                                {c.label}
                            </option>
                        ))}
                    </Select>
                    <Select value={filters.driver_id} onChange={(e) => setFilter('driver_id', e.target.value)}>
                        <option value="">Tous les livreurs</option>
                        <option value="none">Sans livreur</option>
                        {meta.drivers.map((d) => (
                            <option key={d.id} value={d.id}>
                                {d.name}
                            </option>
                        ))}
                    </Select>
                    <Input type="date" value={filters.date_from} onChange={(e) => setFilter('date_from', e.target.value)} title="Du" />
                    <Input type="date" value={filters.date_to} onChange={(e) => setFilter('date_to', e.target.value)} title="Au" />
                </div>
                {activeFilters ? (
                    <div className="mt-2 flex flex-wrap items-center gap-2 text-xs">
                        {categoryLabel ? (
                            <span className="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2 py-1 font-semibold text-blue-700">
                                Catégorie : {categoryLabel}
                                <button type="button" onClick={() => setFilter('status_category', '')} aria-label="Retirer">
                                    <X className="h-3 w-3" />
                                </button>
                            </span>
                        ) : null}
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => {
                                setSearch('');
                                setParams({}, { replace: true });
                            }}
                        >
                            <RotateCcw className="h-3.5 w-3.5" /> Réinitialiser les filtres
                        </Button>
                    </div>
                ) : null}
            </Card>

            {selection.count > 0 ? (
                <div className="sticky top-16 z-20 flex flex-wrap items-center justify-between gap-2 rounded-2xl border border-blue-200 bg-blue-50 px-4 py-2.5 text-sm shadow-sm">
                    <span className="font-semibold text-blue-800">{selection.count} commande(s) sélectionnée(s)</span>
                    <div className="flex items-center gap-2">
                        <Button size="sm" variant="secondary" disabled title="Actions de masse bientôt disponibles">
                            <Layers className="h-3.5 w-3.5" /> Actions groupées (bientôt)
                        </Button>
                        <Button size="sm" variant="ghost" onClick={selection.clear}>
                            Désélectionner
                        </Button>
                    </div>
                </div>
            ) : null}

            <Alert>{error}</Alert>

            {!result ? (
                <Spinner />
            ) : orders.length === 0 ? (
                <Card>
                    <EmptyState>Aucune commande</EmptyState>
                </Card>
            ) : (
                <>
                    {/* Mobile: readable cards with the fields chosen by the user */}
                    <div className="space-y-2 md:hidden">
                        <label className="flex items-center gap-2 px-1 text-xs font-semibold text-slate-500">
                            <Checkbox checked={allChecked} indeterminate={someChecked} onChange={(e) => selection.setMany(pageIds, e.target.checked)} />
                            Tout sélectionner (page)
                        </label>
                        {orders.map((o) => (
                            <article
                                key={o.id}
                                className={`flex gap-3 rounded-2xl border bg-white p-3 shadow-sm ${selection.isSelected(o.id) ? 'border-blue-300 ring-2 ring-blue-100' : 'border-slate-200/80'}`}
                            >
                                <Checkbox checked={selection.isSelected(o.id)} onChange={() => selection.toggle(o.id)} className="mt-1" aria-label={`Sélectionner ${o.reference}`} />
                                <Link to={`/commandes/${o.id}`} className="min-w-0 flex-1">
                                    <div className="text-sm font-bold text-slate-900">{o.reference}</div>
                                    <dl className="mt-1 grid grid-cols-2 gap-x-3 gap-y-1.5">
                                        {mobileColumns.map((c) => (
                                            <div key={c.key} className="min-w-0">
                                                <dt className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">{c.label}</dt>
                                                <dd className="truncate text-xs text-slate-700">{renderCell(c.key, o, meta)}</dd>
                                            </div>
                                        ))}
                                    </dl>
                                </Link>
                            </article>
                        ))}
                    </div>

                    {/* Desktop table */}
                    <Card bodyClassName="p-0" className="hidden md:block">
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-left text-sm">
                                <thead>
                                    <tr className="border-b border-slate-100 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                                        <th className="w-10 px-4 py-3">
                                            <Checkbox
                                                checked={allChecked}
                                                indeterminate={someChecked}
                                                onChange={(e) => selection.setMany(pageIds, e.target.checked)}
                                                aria-label="Tout sélectionner"
                                            />
                                        </th>
                                        {visibleColumns.map((c) => (
                                            <th key={c.key} className="whitespace-nowrap px-3 py-3">
                                                {c.label}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {orders.map((o) => (
                                        <tr
                                            key={o.id}
                                            onClick={() => navigate(`/commandes/${o.id}`)}
                                            className={`cursor-pointer border-b border-slate-50 last:border-0 ${selection.isSelected(o.id) ? 'bg-blue-50/60' : 'hover:bg-slate-50/70'}`}
                                        >
                                            <td className="px-4 py-3" onClick={(e) => e.stopPropagation()}>
                                                <Checkbox checked={selection.isSelected(o.id)} onChange={() => selection.toggle(o.id)} aria-label={`Sélectionner ${o.reference}`} />
                                            </td>
                                            {visibleColumns.map((c) => (
                                                <td key={c.key} className="whitespace-nowrap px-3 py-3 text-slate-500">
                                                    {renderCell(c.key, o, meta)}
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </Card>

                    {lastPage > 1 ? (
                        <div className="flex items-center justify-center gap-2 text-sm">
                            <Button size="sm" variant="secondary" disabled={page <= 1} onClick={() => setFilter('page', String(page - 1))}>
                                <ChevronLeft className="h-4 w-4" />
                            </Button>
                            <span className="font-medium text-slate-600">
                                Page {page} / {lastPage}
                            </span>
                            <Button size="sm" variant="secondary" disabled={page >= lastPage} onClick={() => setFilter('page', String(page + 1))}>
                                <ChevronRight className="h-4 w-4" />
                            </Button>
                        </div>
                    ) : null}
                </>
            )}

            <OrderForm
                open={creating}
                onClose={() => setCreating(false)}
                onSaved={(o) => {
                    setCreating(false);
                    navigate(`/commandes/${o.id}`);
                }}
            />
        </div>
    );
}
