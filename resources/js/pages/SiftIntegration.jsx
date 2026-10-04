import { useCallback, useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { CheckCircle2, Copy, Eye, KeyRound, Link2, Package, RefreshCw, RotateCcw, Save, Search, ShieldCheck, Webhook, XCircle } from 'lucide-react';
import api, { errorMessage, fieldErrors } from '../lib/api';
import { formatDateTime, formatDH } from '../lib/format';
import { Alert, Button, Card, EmptyState, Field, Input, PageHeader, Select, Spinner } from '../components/ui';
import { CarrierSettingsSwitch, Stat, StateBadge, Toggle } from '../components/integrations/IntegrationUi';

const TABS = [
    { key: 'config', label: 'Configuration' },
    { key: 'statuts', label: 'Mapping des statuts' },
    { key: 'colis', label: 'Contrôle des colis' },
    { key: 'webhooks', label: 'Webhooks' },
    { key: 'erreurs', label: 'Journal d’erreurs' },
];

const EDITABLE = ['enabled', 'base_url', 'auth_mode', 'default_allow_open', 'items_mode', 'send_note', 'waybill_format', 'auto_sync', 'bulk_enabled'];

function CopyField({ label, value, mono = true, hint, onReveal, revealLabel }) {
    const [copied, setCopied] = useState(false);
    return (
        <div>
            <div className="mb-1 text-xs font-semibold text-slate-600">{label}</div>
            <div className="flex items-center gap-1.5">
                <div className={`min-w-0 flex-1 truncate rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-700 ring-1 ring-slate-200 ${mono ? 'font-mono' : ''}`}>{value || '—'}</div>
                {onReveal ? (
                    <Button type="button" size="sm" variant="ghost" onClick={onReveal} aria-label={revealLabel}>
                        <Eye className="h-3.5 w-3.5" />
                    </Button>
                ) : null}
                <Button
                    type="button"
                    size="sm"
                    variant="secondary"
                    onClick={async () => {
                        let v = value;
                        if (onReveal && v?.includes('•')) v = await onReveal();
                        navigator.clipboard?.writeText(v || '');
                        setCopied(true);
                        setTimeout(() => setCopied(false), 1500);
                    }}
                    aria-label={`Copier ${label}`}
                >
                    <Copy className="h-3.5 w-3.5" /> {copied ? 'Copié' : 'Copier'}
                </Button>
            </div>
            {hint ? <div className="mt-1 text-[11px] text-slate-500">{hint}</div> : null}
        </div>
    );
}

export default function SiftIntegration({ defaultTab = 'config', title = 'Sift.ma' }) {
    const [params, setParams] = useSearchParams();
    const tab = params.get('tab') || defaultTab;
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);

    const load = useCallback(() => {
        api.get('/integrations/sift')
            .then(({ data: res }) => setData(res.data))
            .catch((e) => setError(errorMessage(e, 'Impossible de charger l’intégration Sift.ma.')));
    }, []);
    useEffect(load, [load]);

    if (!data) return error ? <Alert>{error}</Alert> : <Spinner />;

    return (
        <div className="space-y-4">
            <CarrierSettingsSwitch />
            <PageHeader title={title} subtitle="Transporteur Sift.ma : envoi des colis, étiquettes, suivi par webhooks et contrôle des colis." actions={<StateBadge state={data.state} />} />
            <div className="flex gap-1 overflow-x-auto rounded-xl bg-slate-100 p-1" role="tablist" aria-label="Sections Sift.ma">
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
                        {t.key === 'erreurs' && data.stats.open_errors ? <span className="ml-1.5 rounded-full bg-rose-100 px-1.5 text-[11px] text-rose-700">{data.stats.open_errors}</span> : null}
                    </button>
                ))}
            </div>
            {tab === 'config' ? <ConfigTab data={data} onData={setData} /> : null}
            {tab === 'statuts' ? <StatusMappingTab data={data} onData={setData} /> : null}
            {tab === 'colis' ? <ParcelsTab data={data} /> : null}
            {tab === 'webhooks' ? <WebhookEventsTab data={data} /> : null}
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
    const [secret, setSecret] = useState(null);
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
            const payload = { ...form };
            if (apiKey.trim()) payload.api_key = apiKey.trim();
            const { data: res } = await api.put('/integrations/sift', payload);
            apply(res.data);
            setApiKey('');
            setMsg(res.message);
        });
    };

    const testConnection = () =>
        run('test', async () => {
            try {
                const { data: res } = await api.post('/integrations/sift/test', { api_key: apiKey.trim(), auth_mode: form.auth_mode });
                setTest({ ok: true, message: res.message, at: new Date().toISOString() });
                if (res.data) onData(res.data);
            } catch (e) {
                setTest({ ok: false, message: errorMessage(e, 'Échec du test de connexion.'), at: new Date().toISOString() });
                if (e?.response?.data?.data) onData(e.response.data.data);
            }
        });

    const syncNow = () =>
        run('sync', async () => {
            const { data: res } = await api.post('/integrations/sift/sync');
            apply(res.data);
            setMsg(res.message);
        });

    const reveal = async () => {
        const { data: res } = await api.post('/integrations/sift/webhook/secret');
        setSecret(res.secret);
        return res.secret;
    };
    const regenerate = () =>
        window.confirm('Générer un nouveau secret ? L’ancien sera refusé immédiatement : mettez-le à jour chez Sift.') &&
        run('regen', async () => {
            const { data: res } = await api.post('/integrations/sift/webhook/regenerate');
            setSecret(res.secret);
            onData(res.data);
            setMsg(res.message);
        });
    const registerWebhook = () =>
        run('register', async () => {
            const { data: res } = await api.post('/integrations/sift/webhook/register');
            onData(res.data);
            setMsg(res.message);
        });

    return (
        <form onSubmit={save} className="space-y-4">
            <Alert type="success">{msg}</Alert>
            <Alert>{error}</Alert>
            {data.enabled && data.missing.length ? <div className="rounded-xl bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800 ring-1 ring-amber-200">À compléter avant d’envoyer des colis : {data.missing.join(', ')}.</div> : null}

            <div className="grid gap-4 lg:grid-cols-2">
                <Card title="Connexion API" subtitle="Clé API fournie par Sift.ma (Paramètres → Intégration API)" actions={<KeyRound className="h-4 w-4 text-slate-400" />}>
                    <div className="space-y-4">
                        <Toggle checked={form.enabled} onChange={(v) => set('enabled', v)} label="Intégration active" hint="Désactivée : Sift n’apparaît pas comme disponible dans Commandes." />
                        <Field label="Clé API" hint={data.has_api_key ? `Enregistrée (${data.api_key_hint}). Laissez vide pour la conserver.` : 'Chiffrée côté serveur, jamais affichée ni envoyée au navigateur, masquée dans les journaux.'} error={errors.api_key?.[0]}>
                            <Input type="password" value={apiKey} onChange={(e) => setApiKey(e.target.value)} placeholder={data.has_api_key ? '••••••••' : 'Clé API Sift.ma'} autoComplete="new-password" />
                        </Field>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="URL de l’API" error={errors.base_url?.[0]}>
                                <Input value={form.base_url || ''} onChange={(e) => set('base_url', e.target.value)} placeholder={data.default_base_url} className="font-mono text-xs" />
                            </Field>
                            <Field label="Envoi de la clé">
                                <Select value={form.auth_mode} onChange={(e) => set('auth_mode', e.target.value)}>
                                    <option value="bearer">Authorization: Bearer</option>
                                    <option value="x-api-key">En-tête X-API-Key</option>
                                </Select>
                            </Field>
                        </div>
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
                        <Stat label="Livrés / retournés / annulés" value={`${data.stats.delivered} / ${data.stats.returned} / ${data.stats.cancelled}`} />
                        <Stat label="Dernier webhook" value={data.webhook.last_received_at ? formatDateTime(data.webhook.last_received_at) : 'Aucun'} hint={`${data.stats.webhook_events} événement(s) reçu(s)`} />
                        <Stat label="Erreurs ouvertes" value={data.stats.open_errors} />
                    </dl>
                    <div className="mt-4 flex flex-wrap gap-2">
                        <Button type="button" variant="secondary" size="sm" onClick={syncNow} disabled={!!busy || !data.ready}>
                            <RefreshCw className={`h-3.5 w-3.5 ${busy === 'sync' ? 'animate-spin' : ''}`} /> Synchroniser maintenant
                        </Button>
                    </div>
                    <div className="mt-4 border-t border-slate-100 pt-4">
                        <Toggle checked={form.auto_sync} onChange={(v) => set('auto_sync', v)} label="Synchronisation automatique (secours)" hint="Interroge Sift toutes les 30 minutes pour les colis en cours, en plus des webhooks." />
                    </div>
                </Card>

                <Card title="Webhook Sift" subtitle="À coller dans Sift.ma : URL HTTPS + secret dédié" actions={<Webhook className="h-4 w-4 text-slate-400" />}>
                    <div className="space-y-3">
                        {!data.webhook.https ? <div className="rounded-lg bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800 ring-1 ring-amber-200">URL non HTTPS (environnement local) : en production l’URL sera https://lavfast-flow.com/…</div> : null}
                        <CopyField label="URL du webhook" value={data.webhook.url} />
                        <CopyField label="Secret de signature" value={secret || data.webhook.secret_hint} onReveal={reveal} revealLabel="Afficher le secret" hint="Sert à vérifier la signature HMAC-SHA256 de chaque événement. Les événements non signés ou mal signés sont refusés." />
                        <div className="text-xs text-slate-500">
                            Événements : {data.webhook.events.map((e) => <code key={e} className="mr-1 rounded bg-slate-100 px-1 py-0.5 text-[11px]">{e}</code>)}
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <Button type="button" size="sm" variant="secondary" onClick={registerWebhook} disabled={!!busy || !data.ready}>
                                <Link2 className="h-3.5 w-3.5" /> {busy === 'register' ? 'Enregistrement…' : 'Enregistrer le webhook chez Sift'}
                            </Button>
                            <Button type="button" size="sm" variant="ghost" onClick={regenerate} disabled={!!busy}>
                                <ShieldCheck className="h-3.5 w-3.5" /> Nouveau secret
                            </Button>
                        </div>
                        {data.webhook.remote_id ? <div className="text-[11px] text-slate-500">Webhook Sift n° {data.webhook.remote_id}</div> : null}
                    </div>
                </Card>

                <Card title="Valeurs par défaut des colis" subtitle="Modifiables à chaque envoi dans la fenêtre de validation">
                    <div className="space-y-4">
                        <Toggle checked={form.default_allow_open} onChange={(v) => set('default_allow_open', v)} label="Ouverture du colis autorisée (allowOpen)" />
                        <Field label="Lignes produits" hint="Shopify reste le catalogue. Aucun SKU n’est inventé.">
                            <Select value={form.items_mode} onChange={(e) => set('items_mode', e.target.value)}>
                                <option value="manual">Lignes manuelles (nom, quantité, prix)</option>
                                <option value="sku">SKU liés au stock Sift + lignes manuelles sans SKU</option>
                            </Select>
                        </Field>
                        <Toggle checked={form.send_note} onChange={(v) => set('send_note', v)} label="Envoyer la note de la commande (notes)" />
                        <Field label="Format d’étiquette par défaut">
                            <Select value={form.waybill_format} onChange={(e) => set('waybill_format', e.target.value)}>
                                {Object.entries(data.waybill_formats).map(([k, v]) => (
                                    <option key={k} value={k}>
                                        {v} ({k})
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <div className="rounded-xl border border-amber-200 bg-amber-50/60 p-3">
                            <Toggle checked={form.bulk_enabled} onChange={(v) => set('bulk_enabled', v)} label="Actions groupées Sift" hint="Envoyer à Sift et Étiquettes Sift sur plusieurs commandes. À activer seulement après avoir validé le cycle complet sur une commande test." />
                        </div>
                    </div>
                </Card>
            </div>
        </form>
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
            const { data: res } = await api.put('/integrations/sift', { status_mapping: rows.map(({ raw, code }) => ({ raw, code: code || null })) });
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
            title="Mapping des statuts Sift → Lav'Fast Flow"
            subtitle="Le statut Sift brut est toujours conservé sur le colis et dans l’historique. « Aucun changement » garde le statut actuel de la commande."
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
                <p className="text-xs text-slate-500">Les statuts reçus de Sift qui ne sont pas encore dans la liste y sont ajoutés automatiquement (marqués « reçu »).</p>
            </div>
            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead className="bg-slate-50 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2 sm:px-5">Statut Sift</th>
                            <th className="px-4 py-2">Statut Lav'Fast Flow</th>
                            <th className="px-4 py-2" />
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.map((r, i) => (
                            <tr key={r.raw}>
                                <td className="px-4 py-2 sm:px-5">
                                    <span className="font-semibold text-slate-800">{r.label || r.raw}</span>
                                    <span className="ml-2 font-mono text-[11px] text-slate-400">{r.raw}</span>
                                    {r.seen ? <span className="ml-2 rounded-full bg-violet-50 px-2 py-0.5 text-[10px] font-semibold text-violet-700">reçu</span> : null}
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
                <Input value={newRaw} onChange={(e) => setNewRaw(e.target.value)} placeholder="Ajouter un code statut Sift (ex. in_transit)" className="max-w-xs" />
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

/* ------------------------------------------------------------------ contrôle des colis */

function ParcelsTab({ data }) {
    const [filters, setFilters] = useState({ status: '', city: '', search: '', customOrderNo: '', page: 1 });
    const [rows, setRows] = useState(null);
    const [pagination, setPagination] = useState(null);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);
    const [tracking, setTracking] = useState('');
    const [found, setFound] = useState(null);
    const [q, setQ] = useState('');
    const [products, setProducts] = useState(null);

    const load = useCallback(async (f) => {
        setBusy(true);
        setError(null);
        try {
            const params = Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '' && v !== null));
            const { data: res } = await api.get('/integrations/sift/parcels', { params: { ...params, limit: 20 } });
            setRows(res.data);
            setPagination(res.pagination);
        } catch (e) {
            setError(errorMessage(e));
            setRows([]);
        } finally {
            setBusy(false);
        }
    }, []);

    useEffect(() => {
        if (data.ready) load(filters);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [load, data.ready]);

    const go = (patch) => {
        const next = { ...filters, ...patch };
        setFilters(next);
        load(next);
    };

    async function lookup(e) {
        e.preventDefault();
        setFound(null);
        try {
            const { data: res } = await api.get('/integrations/sift/lookup', { params: { tracking } });
            setFound({ ok: true, parcel: res.data });
        } catch (err) {
            setFound({ ok: false, message: errorMessage(err) });
        }
    }

    async function searchProducts(e) {
        e.preventDefault();
        try {
            const { data: res } = await api.get('/integrations/sift/products', { params: { q } });
            setProducts({ items: res.data });
        } catch (err) {
            setProducts({ error: errorMessage(err), items: [] });
        }
    }

    if (!data.ready) return <Alert>Activez l’intégration et enregistrez la clé API pour consulter les colis Sift.</Alert>;

    return (
        <div className="space-y-4">
            <Card title="Colis chez Sift" subtitle="GET /parcels : liste paginée, pour la resynchronisation et le contrôle. Les colis liés à une commande Lav'Fast Flow sont signalés." bodyClassName="p-0">
                <form
                    className="grid gap-2 p-4 sm:grid-cols-5 sm:px-5"
                    onSubmit={(e) => {
                        e.preventDefault();
                        go({ page: 1 });
                    }}
                >
                    <Select value={filters.status} onChange={(e) => setFilters((f) => ({ ...f, status: e.target.value }))} aria-label="Statut">
                        <option value="">Tous les statuts</option>
                        {Object.entries(data.statuses).map(([k, v]) => (
                            <option key={k} value={k}>
                                {v}
                            </option>
                        ))}
                    </Select>
                    <Input value={filters.city} onChange={(e) => setFilters((f) => ({ ...f, city: e.target.value }))} placeholder="Ville" />
                    <Input value={filters.search} onChange={(e) => setFilters((f) => ({ ...f, search: e.target.value }))} placeholder="Recherche (nom, tél…)" />
                    <Input value={filters.customOrderNo} onChange={(e) => setFilters((f) => ({ ...f, customOrderNo: e.target.value }))} placeholder="N° commande (customOrderNo)" />
                    <Button type="submit" variant="secondary" disabled={busy}>
                        <Search className="h-4 w-4" /> Filtrer
                    </Button>
                </form>
                <div className="px-4 sm:px-5">
                    <Alert>{error}</Alert>
                </div>
                {!rows ? (
                    <Spinner />
                ) : rows.length === 0 ? (
                    <EmptyState>Aucun colis</EmptyState>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-slate-50 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th className="px-4 py-2 sm:px-5">Suivi</th>
                                    <th className="px-4 py-2">customOrderNo</th>
                                    <th className="px-4 py-2">Client</th>
                                    <th className="px-4 py-2">Ville</th>
                                    <th className="px-4 py-2">COD</th>
                                    <th className="px-4 py-2">Statut Sift</th>
                                    <th className="px-4 py-2">Commande</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {rows.map((p) => (
                                    <tr key={p.parcel_id || p.tracking_number} className={p.order ? '' : 'bg-amber-50/30'}>
                                        <td className="px-4 py-2 font-mono text-xs font-semibold text-slate-800 sm:px-5">{p.tracking_number || '—'}</td>
                                        <td className="px-4 py-2 font-mono text-xs text-slate-600">{p.custom_order_no || '—'}</td>
                                        <td className="px-4 py-2">
                                            <div className="font-medium text-slate-800">{p.receiver || '—'}</div>
                                            <div className="text-xs text-slate-500">{p.phone}</div>
                                        </td>
                                        <td className="px-4 py-2 text-slate-600">{p.city || '—'}</td>
                                        <td className="px-4 py-2 text-slate-600">{p.cod_amount !== null ? formatDH(p.cod_amount) : '—'}</td>
                                        <td className="px-4 py-2">
                                            <span className="font-semibold text-violet-700">{p.status_label || p.status || '—'}</span>
                                            {p.sub_status ? <div className="text-[11px] text-slate-500">{p.sub_status}</div> : null}
                                        </td>
                                        <td className="px-4 py-2">
                                            {p.order ? (
                                                <Link to={`/commandes/${p.order.id}`} className="text-xs font-semibold text-blue-600 hover:underline">
                                                    {p.order.reference}
                                                </Link>
                                            ) : (
                                                <span className="text-[11px] font-semibold text-amber-700">Non lié</span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                {pagination ? (
                    <div className="flex items-center justify-between border-t border-slate-100 px-4 py-2 text-xs text-slate-500 sm:px-5">
                        <span>
                            Page {pagination.page || filters.page}
                            {pagination.total !== null && pagination.total !== undefined ? ` · ${pagination.total} colis` : ''}
                        </span>
                        <div className="flex gap-2">
                            <Button size="sm" variant="secondary" disabled={busy || filters.page <= 1} onClick={() => go({ page: filters.page - 1 })}>
                                Précédent
                            </Button>
                            <Button size="sm" variant="secondary" disabled={busy || (pagination.pages ? filters.page >= pagination.pages : (rows || []).length < 20)} onClick={() => go({ page: filters.page + 1 })}>
                                Suivant
                            </Button>
                        </div>
                    </div>
                ) : null}
            </Card>

            <div className="grid gap-4 lg:grid-cols-2">
                <Card title="Recherche par n° de suivi" subtitle="GET /parcels/tracking/{n°}">
                    <form onSubmit={lookup} className="flex gap-2">
                        <Input value={tracking} onChange={(e) => setTracking(e.target.value)} placeholder="N° de suivi Sift" className="font-mono" />
                        <Button type="submit" variant="secondary" disabled={!tracking.trim()}>
                            <Search className="h-4 w-4" /> Chercher
                        </Button>
                    </form>
                    {found ? (
                        found.ok ? (
                            <div className="mt-3 rounded-xl bg-slate-50 px-3 py-2 text-sm">
                                <div className="font-mono font-semibold">{found.parcel.tracking_number}</div>
                                <div className="text-violet-700">{found.parcel.status_label || found.parcel.status}</div>
                                <div className="text-xs text-slate-500">
                                    {found.parcel.receiver} · {found.parcel.city} · {found.parcel.custom_order_no}
                                </div>
                                {found.parcel.order ? (
                                    <Link to={`/commandes/${found.parcel.order.id}`} className="text-xs font-semibold text-blue-600 hover:underline">
                                        Commande {found.parcel.order.reference}
                                    </Link>
                                ) : (
                                    <span className="text-xs text-amber-700">Non lié à une commande</span>
                                )}
                            </div>
                        ) : (
                            <div className="mt-3">
                                <Alert>{found.message}</Alert>
                            </div>
                        )
                    ) : null}
                </Card>
                <Card title="Produits Sift (préparation)" subtitle="GET /products : lecture seule. Shopify reste le catalogue." actions={<Package className="h-4 w-4 text-slate-400" />}>
                    <form onSubmit={searchProducts} className="flex gap-2">
                        <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Nom ou SKU" />
                        <Button type="submit" variant="secondary">
                            <Search className="h-4 w-4" /> Chercher
                        </Button>
                    </form>
                    {products ? (
                        <div className="mt-3 space-y-1">
                            <Alert>{products.error}</Alert>
                            {products.items.map((p) => (
                                <div key={p.id || p.sku} className="flex justify-between rounded-lg bg-slate-50 px-3 py-1.5 text-sm">
                                    <span>
                                        <span className="font-mono text-xs font-semibold">{p.sku || '—'}</span> {p.name}
                                    </span>
                                    <span className="text-xs text-slate-500">stock {p.stock ?? '—'}</span>
                                </div>
                            ))}
                            {!products.error && !products.items.length ? <div className="text-xs text-slate-400">Aucun produit.</div> : null}
                        </div>
                    ) : null}
                </Card>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ webhooks */

const EVENT_STATUS = { processed: ['Traité', 'bg-emerald-50 text-emerald-700'], ignored: ['Ignoré', 'bg-slate-100 text-slate-600'], rejected: ['Refusé', 'bg-rose-50 text-rose-700'], failed: ['Échec', 'bg-rose-50 text-rose-700'], received: ['Reçu', 'bg-amber-50 text-amber-700'] };

function WebhookEventsTab({ data }) {
    const [rows, setRows] = useState(null);
    const [open, setOpen] = useState(null);
    const [error, setError] = useState(null);
    useEffect(() => {
        api.get('/integrations/sift/webhook/events')
            .then(({ data: res }) => setRows(res.data))
            .catch((e) => setError(errorMessage(e)));
    }, []);
    if (!rows) return error ? <Alert>{error}</Alert> : <Spinner />;
    return (
        <Card title="Événements webhook reçus" subtitle={`Signature vérifiée, traitement idempotent (même événement = une seule fois). ${data.webhook.events.join(', ')}.`} bodyClassName="p-0">
            {rows.length === 0 ? (
                <EmptyState>Aucun événement reçu pour l’instant</EmptyState>
            ) : (
                <ul className="divide-y divide-slate-100">
                    {rows.map((r) => {
                        const [label, cls] = EVENT_STATUS[r.status] || [r.status, 'bg-slate-100 text-slate-600'];
                        return (
                            <li key={r.id} className="px-4 py-3 sm:px-5">
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2 text-sm">
                                            <code className="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-700">{r.event_type || 'événement'}</code>
                                            <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${cls}`}>{label}</span>
                                            {r.tracking_number ? <span className="font-mono text-xs text-slate-600">{r.tracking_number}</span> : null}
                                            {r.order_id ? (
                                                <Link to={`/commandes/${r.order_id}`} className="text-xs font-semibold text-blue-600 hover:underline">
                                                    {r.order_reference}
                                                </Link>
                                            ) : null}
                                        </div>
                                        {r.message ? <div className="mt-0.5 text-xs text-slate-600">{r.message}</div> : null}
                                        <div className="text-[11px] text-slate-400">
                                            {formatDateTime(r.created_at)} · <span className="font-mono">{r.event_id}</span>
                                        </div>
                                    </div>
                                    {r.headers ? (
                                        <Button size="sm" variant="ghost" onClick={() => setOpen(open === r.id ? null : r.id)}>
                                            {open === r.id ? 'Masquer' : 'En-têtes'}
                                        </Button>
                                    ) : null}
                                </div>
                                {open === r.id ? <pre className="mt-2 max-h-48 overflow-auto rounded-lg bg-slate-900 p-3 text-[11px] text-slate-100">{JSON.stringify(r.headers, null, 2)}</pre> : null}
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
        api.get('/integrations/sift/logs')
            .then(({ data }) => setRows(data.data))
            .catch((e) => setError(errorMessage(e)));
    }, []);
    useEffect(load, [load]);

    async function retry(row) {
        setBusy(row.id);
        setMsg(null);
        setError(null);
        try {
            const { data } = await api.post(`/integrations/sift/logs/${row.id}/retry`);
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
        <Card title="Journal des erreurs API" subtitle="Appels Sift en échec. La clé API n’est jamais enregistrée (masquée dans les messages et réponses)." bodyClassName="p-0">
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
                            {open === r.id ? <pre className="mt-2 max-h-64 overflow-auto rounded-lg bg-slate-900 p-3 text-[11px] text-slate-100">{JSON.stringify({ payload: r.payload, response: r.response }, null, 2)}</pre> : null}
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}
