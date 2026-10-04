import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { AlertTriangle, CheckCircle2, ExternalLink, FileText, KeyRound, MapPin, Printer, RefreshCw, RotateCcw, Save, Search, Wand2, XCircle } from 'lucide-react';
import api, { errorMessage, fieldErrors } from '../lib/api';
import { formatDateTime } from '../lib/format';
import { Alert, Button, Card, EmptyState, Field, Input, PageHeader, Select, Spinner } from '../components/ui';
import OzonCityPicker from '../components/ozon/OzonCityPicker';
import DocumentLinks from '../components/ozon/DocumentLinks';
import { CarrierSettingsSwitch, Stat, StateBadge, Toggle } from '../components/integrations/IntegrationUi';

const TABS = [
    { key: 'config', label: 'Configuration' },
    { key: 'villes', label: 'Mapping villes' },
    { key: 'statuts', label: 'Mapping des statuts' },
    { key: 'bl', label: 'Bons de livraison' },
    { key: 'erreurs', label: 'Journal d’erreurs' },
];

const EDITABLE = ['enabled', 'customer_id', 'default_stock', 'stock_by_type', 'default_open', 'default_fragile', 'nature_mode', 'nature_text', 'send_products', 'send_note', 'auto_sync', 'bulk_enabled'];

export { Toggle, StateBadge };

export default function OzonIntegration({ defaultTab = 'config', title = 'Ozon Express' }) {
    const [params, setParams] = useSearchParams();
    const tab = params.get('tab') || defaultTab;
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);

    const load = useCallback(() => {
        api.get('/integrations/ozon')
            .then(({ data: res }) => setData(res.data))
            .catch((e) => setError(errorMessage(e, 'Impossible de charger l’intégration Ozon Express.')));
    }, []);
    useEffect(load, [load]);

    if (!data) return error ? <Alert>{error}</Alert> : <Spinner />;

    return (
        <div className="space-y-4">
            <CarrierSettingsSwitch />
            <PageHeader
                title={title}
                subtitle="Transporteur Ozon Express : envoi des colis, suivi, bons de livraison et étiquettes."
                actions={<StateBadge state={data.state} />}
            />
            <div className="flex gap-1 overflow-x-auto rounded-xl bg-slate-100 p-1" role="tablist" aria-label="Sections Ozon Express">
                {TABS.map((t) => (
                    <button
                        key={t.key}
                        type="button"
                        role="tab"
                        aria-selected={tab === t.key}
                        onClick={() => setParams(t.key === defaultTab ? {} : { tab: t.key }, { replace: true })}
                        className={`whitespace-nowrap rounded-lg px-3 py-1.5 text-sm font-semibold transition ${tab === t.key ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800'}`}
                    >
                        {t.label}
                        {t.key === 'villes' && data.unmatched_cities ? <span className="ml-1.5 rounded-full bg-amber-100 px-1.5 text-[11px] text-amber-700">{data.unmatched_cities}</span> : null}
                        {t.key === 'erreurs' && data.open_errors ? <span className="ml-1.5 rounded-full bg-rose-100 px-1.5 text-[11px] text-rose-700">{data.open_errors}</span> : null}
                    </button>
                ))}
            </div>
            {tab === 'config' ? <ConfigTab data={data} onData={setData} /> : null}
            {tab === 'villes' ? <CityMappingTab data={data} onChanged={load} /> : null}
            {tab === 'statuts' ? <StatusMappingTab data={data} onData={setData} /> : null}
            {tab === 'bl' ? <DeliveryNotesTab /> : null}
            {tab === 'erreurs' ? <ErrorLogTab onChanged={load} /> : null}
        </div>
    );
}

/* ------------------------------------------------------------------ configuration */

function ConfigTab({ data, onData }) {
    const pick = (d) => Object.fromEntries(EDITABLE.map((k) => [k, d[k]]));
    const [form, setForm] = useState(() => pick(data));
    const [apiKey, setApiKey] = useState('');
    const [errors, setErrors] = useState({});
    const [msg, setMsg] = useState(null);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState('');
    const [test, setTest] = useState(data.last_tested_at ? { ok: data.last_test_ok, message: data.last_test_message, at: data.last_tested_at } : null);
    const set = (k, v) => setForm((f) => ({ ...f, [k]: v }));

    const apply = (d) => {
        onData(d);
        setForm(pick(d));
    };

    async function run(name, fn) {
        setBusy(name);
        setMsg(null);
        setError(null);
        try {
            await fn();
        } catch (e) {
            setErrors(fieldErrors(e));
            setError(errorMessage(e));
        } finally {
            setBusy('');
        }
    }

    const save = (e) => {
        e?.preventDefault();
        return run('save', async () => {
            setErrors({});
            const payload = { ...form, default_stock: Number(form.default_stock) };
            if (apiKey.trim()) payload.api_key = apiKey.trim();
            const { data: res } = await api.put('/integrations/ozon', payload);
            apply(res.data);
            setApiKey('');
            setMsg(res.message);
        });
    };

    const testConnection = () =>
        run('test', async () => {
            try {
                const { data: res } = await api.post('/integrations/ozon/test', { customer_id: form.customer_id || '', api_key: apiKey.trim() });
                setTest({ ok: true, message: res.message, at: new Date().toISOString() });
                if (res.data) onData(res.data);
            } catch (e) {
                setTest({ ok: false, message: errorMessage(e, 'Échec du test de connexion.'), at: new Date().toISOString() });
                if (e?.response?.data?.data) onData(e.response.data.data);
            }
        });

    const syncCities = () =>
        run('cities', async () => {
            const { data: res } = await api.post('/integrations/ozon/cities/sync');
            apply(res.data);
            setMsg(res.message);
        });

    const syncNow = () =>
        run('sync', async () => {
            const { data: res } = await api.post('/integrations/ozon/sync');
            apply(res.data);
            setMsg(res.message);
        });

    const types = data.options.parcel_types;

    return (
        <form onSubmit={save} className="space-y-4">
            <Alert type="success">{msg}</Alert>
            <Alert>{error}</Alert>
            {data.enabled && data.missing.length ? (
                <div className="rounded-xl bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800 ring-1 ring-amber-200">À compléter avant d’envoyer des colis : {data.missing.join(', ')}.</div>
            ) : null}

            <div className="grid gap-4 lg:grid-cols-2">
                <Card title="Connexion API" subtitle="Identifiants fournis par Ozon Express" actions={<KeyRound className="h-4 w-4 text-slate-400" />}>
                    <div className="space-y-4">
                        <Toggle checked={form.enabled} onChange={(v) => set('enabled', v)} label="Intégration active" hint="Désactivée : Ozon Express n’apparaît pas comme disponible dans Commandes." />
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="ID client" error={errors.customer_id?.[0]}>
                                <Input value={form.customer_id || ''} onChange={(e) => set('customer_id', e.target.value)} placeholder="ex. 12345" autoComplete="off" />
                            </Field>
                            <Field label="Clé API" hint={data.has_api_key ? `Enregistrée (${data.api_key_hint}). Laissez vide pour la conserver.` : 'Chiffrée, jamais affichée ni envoyée au navigateur.'} error={errors.api_key?.[0]}>
                                <Input type="password" value={apiKey} onChange={(e) => setApiKey(e.target.value)} placeholder={data.has_api_key ? '••••••••' : 'Clé API Ozon'} autoComplete="new-password" />
                            </Field>
                        </div>
                        <p className="rounded-lg bg-slate-50 px-3 py-2 font-mono text-[11px] text-slate-500">{data.base_url}</p>
                        <div className="flex flex-wrap items-center gap-2">
                            <Button type="button" variant="secondary" onClick={testConnection} disabled={!!busy}>
                                <RefreshCw className={`h-4 w-4 ${busy === 'test' ? 'animate-spin' : ''}`} /> Tester la connexion
                            </Button>
                            <Button type="submit" disabled={!!busy}>
                                <Save className="h-4 w-4" /> {busy === 'save' ? 'Enregistrement…' : 'Enregistrer'}
                            </Button>
                        </div>
                        {test ? (
                            <div className={`flex items-start gap-2 rounded-xl px-3 py-2 text-sm ${test.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-rose-50 text-rose-700'}`}>
                                {test.ok ? <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" /> : <XCircle className="mt-0.5 h-4 w-4 shrink-0" />}
                                <div>
                                    <div className="font-medium">{test.message}</div>
                                    <div className="text-xs opacity-70">{formatDateTime(test.at)}</div>
                                </div>
                            </div>
                        ) : null}
                    </div>
                </Card>

                <Card title="État & synchronisation">
                    <dl className="grid grid-cols-2 gap-3 text-sm">
                        <Stat label="État" value={<StateBadge state={data.state} />} />
                        <Stat label="Dernière synchro" value={data.last_synced_at ? formatDateTime(data.last_synced_at) : 'Jamais'} hint={data.last_sync_message} />
                        <Stat label="Colis en cours" value={data.stats.active} />
                        <Stat label="Livrés / retournés" value={`${data.stats.delivered} / ${data.stats.returned}`} />
                        <Stat label="BL enregistrés" value={data.stats.delivery_notes} />
                        <Stat label="Villes Ozon" value={data.cities_count} hint={data.cities_synced_at ? `MAJ ${formatDateTime(data.cities_synced_at)}` : 'Jamais synchronisées'} />
                    </dl>
                    <div className="mt-4 flex flex-wrap gap-2">
                        <Button type="button" variant="secondary" size="sm" onClick={syncNow} disabled={!!busy || !data.ready}>
                            <RefreshCw className={`h-3.5 w-3.5 ${busy === 'sync' ? 'animate-spin' : ''}`} /> Synchroniser les statuts
                        </Button>
                        <Button type="button" variant="secondary" size="sm" onClick={syncCities} disabled={!!busy}>
                            <MapPin className="h-3.5 w-3.5" /> {busy === 'cities' ? 'Synchronisation…' : 'Synchroniser les villes'}
                        </Button>
                    </div>
                    <div className="mt-4 border-t border-slate-100 pt-4">
                        <Toggle checked={form.auto_sync} onChange={(v) => set('auto_sync', v)} label="Synchronisation automatique" hint="Suivi groupé des colis en cours toutes les 30 minutes." />
                    </div>
                </Card>

                <Card title="Valeurs par défaut des colis" subtitle="Modifiables à chaque envoi dans la fenêtre de validation">
                    <div className="space-y-4">
                        <Field label="Type de colis (parcel-stock) par défaut" hint="1 = stock chez Ozon, 0 = ramassage chez vous.">
                            <Select value={String(form.default_stock)} onChange={(e) => set('default_stock', Number(e.target.value))}>
                                <option value="0">Ramassage (0)</option>
                                <option value="1">Stock Ozon (1)</option>
                            </Select>
                        </Field>
                        <div className="grid gap-3 sm:grid-cols-2">
                            {types.map((t) => (
                                <Field key={t.value} label={t.label}>
                                    <Select
                                        value={form.stock_by_type?.[t.value] === null || form.stock_by_type?.[t.value] === undefined ? '' : String(form.stock_by_type[t.value])}
                                        onChange={(e) => set('stock_by_type', { ...form.stock_by_type, [t.value]: e.target.value === '' ? null : Number(e.target.value) })}
                                    >
                                        <option value="">Valeur par défaut</option>
                                        <option value="0">Ramassage (0)</option>
                                        <option value="1">Stock Ozon (1)</option>
                                    </Select>
                                </Field>
                            ))}
                        </div>
                        <Toggle checked={form.default_open} onChange={(v) => set('default_open', v)} label="Ouverture du colis autorisée" hint="parcel-open : 1 = oui, 2 = non." />
                        <Toggle checked={form.default_fragile} onChange={(v) => set('default_fragile', v)} label="Colis fragile par défaut" />
                        <p className="text-xs text-slate-500">parcel-replace (échange) est mis automatiquement à 1 pour les envois depuis Retours &amp; échanges.</p>
                    </div>
                </Card>

                <Card title="Contenu envoyé">
                    <div className="space-y-4">
                        <Field label="Nature du colis (parcel-nature)">
                            <Select value={form.nature_mode} onChange={(e) => set('nature_mode', e.target.value)}>
                                <option value="products">Liste des produits de la commande</option>
                                <option value="fixed">Texte fixe</option>
                                <option value="none">Ne pas envoyer</option>
                            </Select>
                        </Field>
                        {form.nature_mode === 'fixed' ? (
                            <Field label="Texte">
                                <Input value={form.nature_text || ''} onChange={(e) => set('nature_text', e.target.value)} maxLength={120} />
                            </Field>
                        ) : null}
                        <Toggle checked={form.send_products} onChange={(v) => set('send_products', v)} label="Envoyer les produits (SKU + quantité)" hint="Seules les lignes avec un SKU Shopify sont envoyées : aucune référence n’est inventée." />
                        <Toggle checked={form.send_note} onChange={(v) => set('send_note', v)} label="Envoyer la note de la commande (parcel-note)" />
                        <div className="rounded-xl border border-amber-200 bg-amber-50/60 p-3">
                            <Toggle
                                checked={form.bulk_enabled}
                                onChange={(v) => set('bulk_enabled', v)}
                                label="Actions groupées Ozon"
                                hint="Envoi groupé, Créer BL Ozon et Étiquettes Ozon sur plusieurs commandes. À activer seulement après avoir validé le cycle complet sur une commande test."
                            />
                        </div>
                    </div>
                </Card>
            </div>
        </form>
    );
}

/* ------------------------------------------------------------------ mapping villes */

function CityMappingTab({ data, onChanged }) {
    const [rows, setRows] = useState(null);
    const [stats, setStats] = useState(null);
    const [filter, setFilter] = useState('all');
    const [q, setQ] = useState('');
    const [msg, setMsg] = useState(null);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState('');

    const load = useCallback(() => {
        api.get('/integrations/ozon/city-mappings')
            .then(({ data: res }) => {
                setRows(res.data);
                setStats(res.stats);
            })
            .catch((e) => setError(errorMessage(e)));
    }, []);
    useEffect(load, [load]);

    async function setCity(city, ozonCityId) {
        setBusy(city);
        setError(null);
        try {
            const { data: res } = await api.put('/integrations/ozon/city-mappings', { city, ozon_city_id: ozonCityId });
            setMsg(res.message);
            load();
            onChanged?.();
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy('');
        }
    }

    async function auto() {
        setBusy('auto');
        try {
            const { data: res } = await api.post('/integrations/ozon/city-mappings/auto');
            setMsg(res.message);
            load();
            onChanged?.();
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy('');
        }
    }

    const shown = useMemo(() => {
        const needle = q.trim().toLowerCase();
        return (rows || []).filter((r) => (filter === 'unmatched' ? !r.ozon_city : true) && (!needle || r.city.toLowerCase().includes(needle)));
    }, [rows, filter, q]);

    if (!rows) return error ? <Alert>{error}</Alert> : <Spinner />;

    return (
        <Card
            title="Mapping villes"
            subtitle={`Ville de la commande → ville Ozon (ID). La ville n’est jamais envoyée en texte. ${stats.unmatched} ville(s) à associer sur ${stats.total}.`}
            actions={
                <Button size="sm" variant="secondary" onClick={auto} disabled={!!busy || !data.cities_count}>
                    <Wand2 className="h-3.5 w-3.5" /> Associer automatiquement
                </Button>
            }
            bodyClassName="p-0"
        >
            <div className="space-y-2 p-4 sm:px-5">
                <Alert type="success">{msg}</Alert>
                <Alert>{error}</Alert>
                {!data.cities_count ? <Alert>Synchronisez d’abord la liste des villes Ozon (onglet Configuration).</Alert> : null}
                <div className="flex flex-wrap items-center gap-2">
                    <div className="relative min-w-48 flex-1">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Rechercher une ville…" className="pl-9" />
                    </div>
                    <Select value={filter} onChange={(e) => setFilter(e.target.value)} className="max-w-48">
                        <option value="all">Toutes les villes</option>
                        <option value="unmatched">À associer</option>
                    </Select>
                </div>
            </div>
            {shown.length === 0 ? (
                <EmptyState>Aucune ville</EmptyState>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="bg-slate-50 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-4 py-2 sm:px-5">Ville (commandes)</th>
                                <th className="px-4 py-2">Commandes</th>
                                <th className="px-4 py-2">Ville Ozon</th>
                                <th className="px-4 py-2">Source</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {shown.map((r) => (
                                <tr key={r.city} className={r.ozon_city ? '' : 'bg-amber-50/40'}>
                                    <td className="px-4 py-2 font-semibold text-slate-800 sm:px-5">{r.city}</td>
                                    <td className="px-4 py-2 text-slate-500">{r.orders}</td>
                                    <td className="min-w-64 px-4 py-2">
                                        <OzonCityPicker value={r.ozon_city} suggestions={r.suggestions} disabled={busy === r.city} onChange={(c) => setCity(r.city, c ? c.id : null)} />
                                    </td>
                                    <td className="px-4 py-2">
                                        {r.ozon_city ? (
                                            <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${r.source === 'manual' ? 'bg-blue-50 text-blue-700' : 'bg-slate-100 text-slate-600'}`}>{r.source === 'manual' ? 'Manuel' : 'Auto'}</span>
                                        ) : (
                                            <span className="inline-flex items-center gap-1 text-[11px] font-semibold text-amber-700">
                                                <AlertTriangle className="h-3.5 w-3.5" /> À associer
                                            </span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Card>
    );
}

/* ------------------------------------------------------------------ mapping statuts */

function StatusMappingTab({ data, onData }) {
    const [rows, setRows] = useState(() => data.status_mapping.map((r) => ({ ...r })));
    const [newRaw, setNewRaw] = useState('');
    const [msg, setMsg] = useState(null);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);
    const statuses = data.options.delivery_statuses;

    async function save() {
        setBusy(true);
        setError(null);
        try {
            const { data: res } = await api.put('/integrations/ozon', { status_mapping: rows.map(({ raw, code }) => ({ raw, code: code || null })) });
            onData(res.data);
            setRows(res.data.status_mapping.map((r) => ({ ...r })));
            setMsg('Mapping des statuts enregistré.');
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy(false);
        }
    }

    return (
        <Card
            title="Mapping des statuts"
            subtitle="Statut Ozon (brut, conservé tel quel sur le colis) → statut Lav'Fast Flow. « Aucun changement » garde le statut actuel."
            actions={
                <Button size="sm" onClick={save} disabled={busy}>
                    <Save className="h-3.5 w-3.5" /> {busy ? 'Enregistrement…' : 'Enregistrer'}
                </Button>
            }
            bodyClassName="p-0"
        >
            <div className="space-y-2 p-4 sm:px-5">
                <Alert type="success">{msg}</Alert>
                <Alert>{error}</Alert>
                <p className="text-xs text-slate-500">Les statuts reçus d’Ozon qui ne sont pas encore dans la liste y sont ajoutés automatiquement (marqués « reçu »).</p>
            </div>
            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead className="bg-slate-50 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2 sm:px-5">Statut Ozon</th>
                            <th className="px-4 py-2">Statut Lav'Fast Flow</th>
                            <th className="px-4 py-2" />
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.map((r, i) => (
                            <tr key={r.raw}>
                                <td className="px-4 py-2 sm:px-5">
                                    <span className="font-semibold text-slate-800">{r.raw}</span>
                                    {r.seen ? <span className="ml-2 rounded-full bg-teal-50 px-2 py-0.5 text-[10px] font-semibold text-teal-700">reçu</span> : null}
                                </td>
                                <td className="px-4 py-2">
                                    <Select value={r.code || ''} onChange={(e) => setRows((list) => list.map((x, j) => (j === i ? { ...x, code: e.target.value || null } : x)))} aria-label={`Statut pour ${r.raw}`}>
                                        <option value="">Aucun changement</option>
                                        {statuses.map((s) => (
                                            <option key={s.code} value={s.code}>
                                                {s.name}
                                                {s.is_active ? '' : ' (inactif)'}
                                            </option>
                                        ))}
                                    </Select>
                                </td>
                                <td className="px-4 py-2 text-right">
                                    <button type="button" className="text-xs font-semibold text-slate-400 hover:text-rose-600" onClick={() => setRows((list) => list.filter((_, j) => j !== i))}>
                                        Retirer
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <div className="flex flex-wrap items-center gap-2 border-t border-slate-100 p-4 sm:px-5">
                <Input value={newRaw} onChange={(e) => setNewRaw(e.target.value)} placeholder="Ajouter un statut Ozon (texte exact)" className="max-w-xs" />
                <Button
                    size="sm"
                    variant="secondary"
                    disabled={!newRaw.trim() || rows.some((r) => r.raw.toLowerCase() === newRaw.trim().toLowerCase())}
                    onClick={() => {
                        setRows((list) => [...list, { raw: newRaw.trim(), code: null, seen: false }]);
                        setNewRaw('');
                    }}
                >
                    Ajouter
                </Button>
            </div>
        </Card>
    );
}

/* ------------------------------------------------------------------ bons de livraison */

export { DocumentLinks };

function DeliveryNotesTab() {
    const [rows, setRows] = useState(null);
    const [error, setError] = useState(null);
    useEffect(() => {
        api.get('/integrations/ozon/delivery-notes')
            .then(({ data }) => setRows(data.data))
            .catch((e) => setError(errorMessage(e)));
    }, []);
    if (!rows) return error ? <Alert>{error}</Alert> : <Spinner />;
    const stateLabel = { saved: ['Enregistré', 'bg-emerald-50 text-emerald-700'], filled: ['Colis ajoutés (non enregistré)', 'bg-amber-50 text-amber-700'], created: ['Créé (vide)', 'bg-amber-50 text-amber-700'], failed: ['Échec', 'bg-rose-50 text-rose-700'], creating: ['En cours', 'bg-slate-100 text-slate-600'] };

    return (
        <Card title="Bons de livraison Ozon" subtitle="Créés depuis Commandes (« Créer BL Ozon ») ou depuis la fiche commande." bodyClassName="p-0">
            {rows.length === 0 ? (
                <EmptyState>Aucun bon de livraison</EmptyState>
            ) : (
                <ul className="divide-y divide-slate-100">
                    {rows.map((n) => {
                        const [label, cls] = stateLabel[n.state] || [n.state, 'bg-slate-100 text-slate-600'];
                        return (
                            <li key={n.id} className="space-y-1.5 px-4 py-3 sm:px-5">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div className="flex items-center gap-2">
                                        <span className="font-mono text-sm font-bold text-slate-900">{n.ref || '—'}</span>
                                        <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${cls}`}>{label}</span>
                                        <span className="text-xs text-slate-500">{n.parcels_count} colis</span>
                                    </div>
                                    <span className="text-xs text-slate-400">
                                        {formatDateTime(n.saved_at || n.created_at)}
                                        {n.created_by ? ` · ${n.created_by}` : ''}
                                    </span>
                                </div>
                                <div className="flex flex-wrap gap-1.5">
                                    {n.items.map((i) => (
                                        <Link key={i.tracking_number} to={`/commandes/${i.order_id}`} className="rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-700 hover:bg-slate-200">
                                            {i.reference} · <span className="font-mono">{i.tracking_number}</span>
                                        </Link>
                                    ))}
                                </div>
                                {n.state === 'saved' ? <DocumentLinks documents={n.documents} /> : null}
                                {n.last_error ? <div className="text-xs text-rose-600">{n.last_error}</div> : null}
                            </li>
                        );
                    })}
                </ul>
            )}
        </Card>
    );
}

/* ------------------------------------------------------------------ journal d'erreurs */

function ErrorLogTab({ onChanged }) {
    const [rows, setRows] = useState(null);
    const [open, setOpen] = useState(null);
    const [busy, setBusy] = useState(null);
    const [msg, setMsg] = useState(null);
    const [error, setError] = useState(null);
    const load = useCallback(() => {
        api.get('/integrations/ozon/logs')
            .then(({ data }) => setRows(data.data))
            .catch((e) => setError(errorMessage(e)));
    }, []);
    useEffect(load, [load]);

    async function retry(row) {
        setBusy(row.id);
        setMsg(null);
        setError(null);
        try {
            const { data } = await api.post(`/integrations/ozon/logs/${row.id}/retry`);
            setMsg(data.message);
            load();
            onChanged?.();
        } catch (e) {
            setError(errorMessage(e));
            load();
        } finally {
            setBusy(null);
        }
    }

    if (!rows) return error ? <Alert>{error}</Alert> : <Spinner />;

    return (
        <Card title="Journal des erreurs API" subtitle="Appels Ozon en échec. La clé API n’est jamais enregistrée (masquée dans les URL et messages)." bodyClassName="p-0">
            <div className="space-y-2 px-4 pt-3 sm:px-5">
                <Alert type="success">{msg}</Alert>
                <Alert>{error}</Alert>
            </div>
            {rows.length === 0 ? (
                <EmptyState>Aucune erreur</EmptyState>
            ) : (
                <ul className="divide-y divide-slate-100">
                    {rows.map((r) => (
                        <li key={r.id} className="px-4 py-3 sm:px-5">
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-2 text-sm">
                                        <span className="font-semibold text-slate-800">{r.action_label}</span>
                                        <span className="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] text-slate-600">{r.endpoint}</span>
                                        {r.order_id ? (
                                            <Link to={`/commandes/${r.order_id}`} className="text-xs font-semibold text-blue-600 hover:underline">
                                                {r.order_reference}
                                            </Link>
                                        ) : null}
                                        {r.resolved ? <span className="rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">Résolu</span> : null}
                                    </div>
                                    <div className="mt-0.5 text-sm text-rose-700">{r.message}</div>
                                    <div className="text-xs text-slate-400">
                                        {formatDateTime(r.created_at)} · {r.user}
                                        {r.http_status ? ` · HTTP ${r.http_status}` : ''}
                                        {r.retried_at ? ` · relancé ${formatDateTime(r.retried_at)}` : ''}
                                    </div>
                                </div>
                                <div className="flex items-center gap-2">
                                    {r.payload || r.response ? (
                                        <Button size="sm" variant="ghost" onClick={() => setOpen(open === r.id ? null : r.id)}>
                                            {open === r.id ? 'Masquer' : 'Détails'}
                                        </Button>
                                    ) : null}
                                    {r.retryable ? (
                                        <Button size="sm" variant="secondary" onClick={() => retry(r)} disabled={busy === r.id}>
                                            <RotateCcw className={`h-3.5 w-3.5 ${busy === r.id ? 'animate-spin' : ''}`} /> Réessayer
                                        </Button>
                                    ) : null}
                                </div>
                            </div>
                            {open === r.id ? (
                                <pre className="mt-2 max-h-64 overflow-auto rounded-lg bg-slate-900 p-3 text-[11px] text-slate-100">{JSON.stringify({ payload: r.payload, response: r.response }, null, 2)}</pre>
                            ) : null}
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}
