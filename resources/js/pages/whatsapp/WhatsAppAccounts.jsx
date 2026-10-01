import { useCallback, useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import {
    KeyRound,
    Link2,
    Phone,
    Plus,
    RefreshCw,
    ShieldAlert,
    Unplug,
} from 'lucide-react';
import { statusLabel } from './helpers';

const ERROR_MESSAGES = {
    not_configured: 'L’application Meta n’est pas configurée.',
    state: 'Session OAuth expirée. Réessayez.',
    oauth: 'Échec de la connexion Meta.',
};

const inputClass =
    'h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm outline-none transition focus:border-emerald-300 focus:bg-white focus:ring-4 focus:ring-emerald-500/10';

function formatDate(value) {
    if (!value) return '—';
    try {
        return new Intl.DateTimeFormat('fr-FR', {
            dateStyle: 'short',
            timeStyle: 'short',
        }).format(new Date(value));
    } catch {
        return value;
    }
}

export default function WhatsAppAccounts() {
    const [searchParams, setSearchParams] = useSearchParams();
    const [accounts, setAccounts] = useState([]);
    const [meta, setMeta] = useState(null);
    const [loading, setLoading] = useState(true);
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');
    const [showAdd, setShowAdd] = useState(false);
    const [showMetaCreds, setShowMetaCreds] = useState(false);
    const [showMigration, setShowMigration] = useState(false);
    const [migration, setMigration] = useState(null);
    const [saving, setSaving] = useState(false);

    const [appId, setAppId] = useState('');
    const [appSecret, setAppSecret] = useState('');
    const [configId, setConfigId] = useState('');
    const [verifyToken, setVerifyToken] = useState('');

    const [form, setForm] = useState({
        name: '',
        display_phone_number: '',
        phone_number_id: '',
        waba_id: '',
        access_token: '',
    });

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const { data } = await window.axios.get('/api/whatsapp/accounts');
            setAccounts(data.accounts || []);
            setMeta(data.meta || null);
            setAppId(data.meta?.app_id || '');
            setConfigId(data.meta?.config_id || '');
        } catch (err) {
            setError(err.response?.data?.message || 'Impossible de charger les comptes WhatsApp.');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    useEffect(() => {
        if (searchParams.get('connected') === '1') {
            setMessage('Compte WhatsApp connecté avec Meta.');
            searchParams.delete('connected');
            setSearchParams(searchParams, { replace: true });
            load();
        }
        const errKey = searchParams.get('error');
        if (errKey) {
            setError(ERROR_MESSAGES[errKey] || 'Une erreur est survenue.');
            searchParams.delete('error');
            setSearchParams(searchParams, { replace: true });
        }
    }, [searchParams, setSearchParams, load]);

    const statusTone = useMemo(
        () => ({
            connected: 'bg-emerald-50 text-emerald-700 ring-emerald-600/15',
            disconnected: 'bg-slate-100 text-slate-600 ring-slate-500/10',
            error: 'bg-rose-50 text-rose-700 ring-rose-600/15',
        }),
        []
    );

    async function handleSaveMeta(event) {
        event.preventDefault();
        setSaving(true);
        setError('');
        setMessage('');
        try {
            const payload = {
                app_id: appId.trim(),
                config_id: configId.trim() || undefined,
                webhook_verify_token: verifyToken.trim() || undefined,
            };
            if (appSecret.trim()) payload.app_secret = appSecret.trim();
            const { data } = await window.axios.post('/api/whatsapp/meta/credentials', payload);
            setMessage(data.message);
            setAppSecret('');
            setShowMetaCreds(false);
            await load();
        } catch (err) {
            setError(err.response?.data?.message || 'Enregistrement impossible.');
        } finally {
            setSaving(false);
        }
    }

    async function handleConnectMeta(account = null) {
        setSaving(true);
        setError('');
        try {
            const { data } = await window.axios.post('/api/whatsapp/meta/connect', {
                account_id: account?.id,
                account_name: account?.name || form.name || 'WhatsApp Business',
            });
            if (data.authorization_url) {
                window.location.href = data.authorization_url;
                return;
            }
            setError('URL d’autorisation Meta manquante.');
        } catch (err) {
            setError(err.response?.data?.message || 'Connexion Meta impossible.');
        } finally {
            setSaving(false);
        }
    }

    async function handleAdd(event) {
        event.preventDefault();
        setSaving(true);
        setError('');
        try {
            const { data } = await window.axios.post('/api/whatsapp/accounts', {
                ...form,
                connection_method: form.access_token ? 'manual' : 'pending',
            });
            setMessage(data.message);
            setShowAdd(false);
            setForm({
                name: '',
                display_phone_number: '',
                phone_number_id: '',
                waba_id: '',
                access_token: '',
            });
            await load();
        } catch (err) {
            setError(err.response?.data?.message || 'Ajout impossible.');
        } finally {
            setSaving(false);
        }
    }

    async function handleDisconnect(account) {
        if (!window.confirm(`Déconnecter « ${account.name} » ?`)) return;
        try {
            await window.axios.delete(`/api/whatsapp/accounts/${account.id}`);
            setMessage('Numéro déconnecté.');
            await load();
        } catch (err) {
            setError(err.response?.data?.message || 'Déconnexion impossible.');
        }
    }

    async function handleSync(account) {
        try {
            const { data } = await window.axios.post(`/api/whatsapp/accounts/${account.id}/sync`);
            setMessage(data.message);
            await load();
        } catch (err) {
            setError(err.response?.data?.message || 'Synchronisation impossible.');
        }
    }

    async function openMigration() {
        setShowMigration(true);
        try {
            const { data } = await window.axios.get('/api/whatsapp/migration-checklist');
            setMigration(data);
        } catch (err) {
            setError(err.response?.data?.message || 'Impossible de charger la checklist.');
        }
    }

    return (
        <div className="space-y-4 sm:space-y-6">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">
                        Comptes / Numéros
                    </h1>
                    <p className="mt-1 text-sm font-medium text-slate-500">
                        Connectez un ou plusieurs numéros WhatsApp Business à votre société.
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <button
                        type="button"
                        onClick={() => setShowMetaCreds((v) => !v)}
                        className="inline-flex h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-sm font-bold text-slate-700 shadow-sm"
                    >
                        <KeyRound className="h-4 w-4" />
                        Meta App
                    </button>
                    <button
                        type="button"
                        onClick={openMigration}
                        className="inline-flex h-10 items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3 text-sm font-bold text-amber-800 shadow-sm"
                    >
                        <ShieldAlert className="h-4 w-4" />
                        Migrer un numéro
                    </button>
                    <button
                        type="button"
                        onClick={() => setShowAdd(true)}
                        className="inline-flex h-10 items-center gap-2 rounded-xl bg-emerald-600 px-4 text-sm font-bold text-white shadow-sm shadow-emerald-600/20"
                    >
                        <Plus className="h-4 w-4" />
                        Ajouter un numéro
                    </button>
                </div>
            </div>

            {message ? (
                <div className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
                    {message}
                </div>
            ) : null}
            {error ? (
                <div className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-800">
                    {error}
                </div>
            ) : null}

            {meta ? (
                <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p className="text-sm font-bold text-slate-900">Connexion Meta</p>
                            <p className="mt-1 text-xs font-medium text-slate-500">
                                Webhook : <span className="font-mono text-slate-700">{meta.webhook_url}</span>
                            </p>
                            <p className="mt-1 text-xs font-medium text-slate-500">
                                Redirect OAuth :{' '}
                                <span className="font-mono text-slate-700">{meta.redirect_uri}</span>
                            </p>
                        </div>
                        <button
                            type="button"
                            disabled={!meta.configured || saving}
                            onClick={() => handleConnectMeta()}
                            className="inline-flex h-10 items-center gap-2 rounded-xl bg-[#1877F2] px-4 text-sm font-bold text-white shadow-sm disabled:opacity-50"
                        >
                            <Link2 className="h-4 w-4" />
                            Connecter avec Meta
                        </button>
                    </div>
                    {!meta.configured ? (
                        <p className="mt-3 text-sm font-medium text-amber-700">
                            Configurez d’abord l’App ID / App Secret Meta (bouton Meta App).
                        </p>
                    ) : null}
                </div>
            ) : null}

            {showMetaCreds ? (
                <form
                    onSubmit={handleSaveMeta}
                    className="space-y-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5"
                >
                    <h2 className="text-sm font-bold text-slate-900">Identifiants Meta (serveur uniquement)</h2>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <label className="block text-xs font-bold text-slate-500">
                            App ID
                            <input className={`${inputClass} mt-1`} value={appId} onChange={(e) => setAppId(e.target.value)} required />
                        </label>
                        <label className="block text-xs font-bold text-slate-500">
                            App Secret {meta?.has_app_secret ? '(laisser vide pour conserver)' : ''}
                            <input
                                type="password"
                                className={`${inputClass} mt-1`}
                                value={appSecret}
                                onChange={(e) => setAppSecret(e.target.value)}
                                autoComplete="new-password"
                            />
                        </label>
                        <label className="block text-xs font-bold text-slate-500">
                            Embedded Signup Config ID
                            <input className={`${inputClass} mt-1`} value={configId} onChange={(e) => setConfigId(e.target.value)} />
                        </label>
                        <label className="block text-xs font-bold text-slate-500">
                            Webhook Verify Token
                            <input className={`${inputClass} mt-1`} value={verifyToken} onChange={(e) => setVerifyToken(e.target.value)} placeholder="Généré auto si vide" />
                        </label>
                    </div>
                    <button
                        type="submit"
                        disabled={saving}
                        className="inline-flex h-10 items-center rounded-xl bg-slate-900 px-4 text-sm font-bold text-white disabled:opacity-60"
                    >
                        Enregistrer
                    </button>
                </form>
            ) : null}

            {showAdd ? (
                <form
                    onSubmit={handleAdd}
                    className="space-y-3 rounded-2xl border border-emerald-200 bg-emerald-50/40 p-4 shadow-sm sm:p-5"
                >
                    <h2 className="text-sm font-bold text-slate-900">Nouveau numéro WhatsApp</h2>
                    <p className="text-xs font-medium text-slate-500">
                        Préférez « Connecter avec Meta ». L’ajout manuel (Phone Number ID + token) est réservé à
                        la configuration technique — le token n’est jamais renvoyé au navigateur ensuite.
                    </p>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <label className="block text-xs font-bold text-slate-500 sm:col-span-2">
                            Nom du compte (ex. Service commandes)
                            <input
                                className={`${inputClass} mt-1`}
                                value={form.name}
                                onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
                                required
                            />
                        </label>
                        <label className="block text-xs font-bold text-slate-500">
                            Numéro affiché
                            <input
                                className={`${inputClass} mt-1`}
                                value={form.display_phone_number}
                                onChange={(e) => setForm((f) => ({ ...f, display_phone_number: e.target.value }))}
                            />
                        </label>
                        <label className="block text-xs font-bold text-slate-500">
                            Phone Number ID
                            <input
                                className={`${inputClass} mt-1`}
                                value={form.phone_number_id}
                                onChange={(e) => setForm((f) => ({ ...f, phone_number_id: e.target.value }))}
                            />
                        </label>
                        <label className="block text-xs font-bold text-slate-500">
                            WABA ID
                            <input
                                className={`${inputClass} mt-1`}
                                value={form.waba_id}
                                onChange={(e) => setForm((f) => ({ ...f, waba_id: e.target.value }))}
                            />
                        </label>
                        <label className="block text-xs font-bold text-slate-500">
                            Access token (serveur)
                            <input
                                type="password"
                                className={`${inputClass} mt-1`}
                                value={form.access_token}
                                onChange={(e) => setForm((f) => ({ ...f, access_token: e.target.value }))}
                                autoComplete="new-password"
                            />
                        </label>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <button
                            type="button"
                            onClick={() => handleConnectMeta({ name: form.name })}
                            className="inline-flex h-10 items-center gap-2 rounded-xl bg-[#1877F2] px-4 text-sm font-bold text-white"
                        >
                            Continuer avec Meta
                        </button>
                        <button
                            type="submit"
                            disabled={saving}
                            className="inline-flex h-10 items-center rounded-xl bg-emerald-600 px-4 text-sm font-bold text-white disabled:opacity-60"
                        >
                            Enregistrer
                        </button>
                        <button
                            type="button"
                            onClick={() => setShowAdd(false)}
                            className="inline-flex h-10 items-center rounded-xl border border-slate-200 bg-white px-4 text-sm font-bold text-slate-600"
                        >
                            Annuler
                        </button>
                    </div>
                    <p className="text-[11px] font-medium text-slate-500">
                        « Connecter WhatsApp Business » (QR) n’est proposé que si Meta l’expose officiellement pour
                        le compte — aucun parcours WhatsApp Web non officiel n’est utilisé.
                    </p>
                </form>
            ) : null}

            {showMigration && migration ? (
                <div className="rounded-2xl border border-amber-200 bg-amber-50 p-4 shadow-sm sm:p-5">
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <h2 className="text-sm font-bold text-amber-950">{migration.title}</h2>
                            <p className="mt-1 text-sm font-medium text-amber-900">{migration.warning}</p>
                        </div>
                        <button
                            type="button"
                            onClick={() => setShowMigration(false)}
                            className="text-sm font-bold text-amber-800"
                        >
                            Fermer
                        </button>
                    </div>
                    <ol className="mt-4 list-decimal space-y-2 pl-5 text-sm font-medium text-amber-950">
                        {(migration.steps || []).map((step) => (
                            <li key={step}>{step}</li>
                        ))}
                    </ol>
                </div>
            ) : null}

            <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                {loading ? (
                    <p className="px-4 py-10 text-center text-sm font-medium text-slate-500">Chargement…</p>
                ) : accounts.length === 0 ? (
                    <p className="px-4 py-10 text-center text-sm font-medium text-slate-500">
                        Aucun numéro connecté. Ajoutez votre premier numéro WhatsApp Business.
                    </p>
                ) : (
                    <div className="divide-y divide-slate-100">
                        {accounts.map((account) => (
                            <div
                                key={account.id}
                                className="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5"
                            >
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <p className="truncate text-sm font-bold text-slate-900">{account.name}</p>
                                        <span
                                            className={`inline-flex rounded-full px-2 py-0.5 text-[11px] font-bold ring-1 ring-inset ${statusTone[account.status] || statusTone.disconnected}`}
                                        >
                                            {statusLabel(account.status)}
                                        </span>
                                        <span
                                            className={`inline-flex rounded-full px-2 py-0.5 text-[11px] font-bold ring-1 ring-inset ${account.is_active ? 'bg-blue-50 text-blue-700 ring-blue-600/15' : 'bg-slate-100 text-slate-500 ring-slate-500/10'}`}
                                        >
                                            {account.is_active ? 'Actif' : 'Inactif'}
                                        </span>
                                    </div>
                                    <p className="mt-1 flex items-center gap-1.5 text-sm font-medium text-slate-600">
                                        <Phone className="h-3.5 w-3.5" />
                                        {account.display_phone_number || account.phone_number || '—'}
                                    </p>
                                    <p className="mt-1 text-xs font-medium text-slate-450 text-slate-500">
                                        WABA {account.waba_id || '—'} · Phone ID {account.phone_number_id || '—'} ·
                                        Sync {formatDate(account.last_synced_at)}
                                    </p>
                                    {account.meta_error ? (
                                        <p className="mt-1 text-xs font-medium text-rose-600">{account.meta_error}</p>
                                    ) : null}
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        onClick={() => handleConnectMeta(account)}
                                        className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 text-xs font-bold text-slate-700"
                                    >
                                        <Link2 className="h-3.5 w-3.5" />
                                        Meta
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => handleSync(account)}
                                        className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 text-xs font-bold text-slate-700"
                                    >
                                        <RefreshCw className="h-3.5 w-3.5" />
                                        Sync
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => handleDisconnect(account)}
                                        className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-rose-200 bg-rose-50 px-3 text-xs font-bold text-rose-700"
                                    >
                                        <Unplug className="h-3.5 w-3.5" />
                                        Déconnecter
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}
