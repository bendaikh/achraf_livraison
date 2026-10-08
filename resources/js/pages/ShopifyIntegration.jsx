import { useCallback, useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { KeyRound, Link2, RefreshCw, Store, Unplug } from 'lucide-react';

const ERROR_MESSAGES = {
    not_configured: 'L’application Shopify n’est pas configurée.',
    invalid_shop: 'Domaine boutique invalide.',
    hmac: 'Vérification de sécurité Shopify échouée.',
    state: 'Session OAuth expirée. Réessayez.',
    oauth: 'Échec de la connexion OAuth Shopify.',
};

const inputClass =
    'h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm outline-none transition focus:border-blue-300 focus:bg-white focus:ring-4 focus:ring-blue-500/10';

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

export default function ShopifyIntegration() {
    const [searchParams, setSearchParams] = useSearchParams();
    const [status, setStatus] = useState(null);
    const [shopInput, setShopInput] = useState('');
    const [clientId, setClientId] = useState('');
    const [clientSecret, setClientSecret] = useState('');
    const [scopes, setScopes] = useState('read_orders,read_customers');
    const [loading, setLoading] = useState(true);
    const [savingCredentials, setSavingCredentials] = useState(false);
    const [connecting, setConnecting] = useState(false);
    const [syncing, setSyncing] = useState(false);
    const [registering, setRegistering] = useState(false);
    const [disconnecting, setDisconnecting] = useState(false);
    const [logs, setLogs] = useState([]);
    const [logFilter, setLogFilter] = useState({ direction: '', status: '', entity: '' });
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const statusRes = await window.axios.get('/api/integrations/shopify');
            setStatus(statusRes.data);
            setClientId(statusRes.data.client_id || '');
            setScopes(statusRes.data.scopes || 'read_orders,read_customers');
            if (statusRes.data.shop?.shop_domain) {
                setShopInput(statusRes.data.shop.shop_domain);
            }
            if (statusRes.data.connected) {
                const logsRes = await window.axios.get('/api/integrations/shopify/logs');
                setLogs(logsRes.data.data || []);
            } else {
                setLogs([]);
            }
        } catch (err) {
            setError(err.response?.data?.message || 'Impossible de charger l’intégration Shopify.');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    useEffect(() => {
        if (searchParams.get('connected') === '1') {
            setMessage('Boutique Shopify connectée. Les commandes sont visibles dans Commandes.');
            setError('');
            searchParams.delete('connected');
            setSearchParams(searchParams, { replace: true });
            load();
        }

        const errKey = searchParams.get('error');
        if (errKey) {
            setError(ERROR_MESSAGES[errKey] || 'Une erreur est survenue.');
            setMessage('');
            searchParams.delete('error');
            setSearchParams(searchParams, { replace: true });
        }
    }, [searchParams, setSearchParams, load]);

    async function handleSaveCredentials(event) {
        event.preventDefault();
        setSavingCredentials(true);
        setError('');
        setMessage('');

        try {
            const payload = {
                client_id: clientId.trim(),
                scopes: scopes.trim() || 'read_orders,read_customers',
            };
            if (clientSecret.trim()) {
                payload.client_secret = clientSecret.trim();
            }

            const { data } = await window.axios.post('/api/integrations/shopify/credentials', payload);
            setMessage(data.message);
            setClientSecret('');
            await load();
        } catch (err) {
            setError(err.response?.data?.message || 'Enregistrement impossible.');
        } finally {
            setSavingCredentials(false);
        }
    }

    async function handleConnect(event) {
        event.preventDefault();
        setConnecting(true);
        setError('');
        setMessage('');

        try {
            const { data } = await window.axios.post('/api/integrations/shopify/connect', {
                shop: shopInput.trim(),
            });
            window.location.href = data.authorization_url;
        } catch (err) {
            setError(err.response?.data?.message || 'Connexion impossible.');
            setConnecting(false);
        }
    }

    async function handleReconcile() {
        setSyncing(true);
        setError('');
        setMessage('');
        try {
            const { data } = await window.axios.post('/api/integrations/shopify/reconcile');
            setMessage(data.message || 'Synchronisation lancée.');
            await load();
        } catch (err) {
            setError(err.response?.data?.message || 'Synchronisation impossible.');
        } finally {
            setSyncing(false);
        }
    }

    async function retryLog(id) {
        setError('');
        try {
            await window.axios.post(`/api/integrations/shopify/logs/${id}/retry`);
            setMessage('Nouvel essai programmé.');
            await load();
        } catch (err) {
            setError(err.response?.data?.message || 'Impossible de relancer.');
        }
    }

    async function handleSync() {
        setSyncing(true);
        setError('');
        setMessage('');
        try {
            const { data } = await window.axios.post('/api/integrations/shopify/sync');
            setMessage(`${data.message} Voir la section Commandes.`);
            await load();
        } catch (err) {
            setError(err.response?.data?.message || 'Synchronisation impossible.');
        } finally {
            setSyncing(false);
        }
    }

    async function handleRegisterWebhooks() {
        setRegistering(true);
        setError('');
        setMessage('');
        try {
            const { data } = await window.axios.post('/api/integrations/shopify/webhooks');
            setMessage(data.message);
        } catch (err) {
            setError(err.response?.data?.message || 'Impossible de réenregistrer les webhooks.');
        } finally {
            setRegistering(false);
        }
    }

    async function handleDisconnect() {
        if (!window.confirm('Déconnecter la boutique Shopify ?')) return;

        setDisconnecting(true);
        setError('');
        setMessage('');
        try {
            const { data } = await window.axios.post('/api/integrations/shopify/disconnect');
            setMessage(data.message);
            await load();
        } catch (err) {
            setError(err.response?.data?.message || 'Déconnexion impossible.');
        } finally {
            setDisconnecting(false);
        }
    }

    if (loading && !status) {
        return (
            <div className="flex min-h-[40vh] items-center justify-center text-sm font-medium text-slate-500">
                Chargement…
            </div>
        );
    }

    const connected = Boolean(status?.connected && status?.shop);

    return (
        <div className="mx-auto max-w-4xl space-y-6">
            <div>
                <h1 className="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">Shopify</h1>
                <p className="mt-1 text-sm font-medium text-slate-500">
                    Configurez l’app Shopify puis connectez votre boutique pour synchroniser les commandes.
                </p>
            </div>

            {message ? (
                <div className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
                    {message}{' '}
                    {connected ? (
                        <Link to="/commandes" className="font-bold underline underline-offset-2">
                            Ouvrir Commandes
                        </Link>
                    ) : null}
                </div>
            ) : null}

            {error ? (
                <div className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700">
                    {error}
                </div>
            ) : null}

            {connected && status.shop.needs_reconnect ? (
                <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900">
                    Nouvelles autorisations Shopify nécessaires — Reconnecter Shopify
                </div>
            ) : null}

            <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-200/40 sm:p-7">
                <div className="flex items-start gap-3">
                    <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-slate-100 text-slate-700">
                        <KeyRound className="h-5 w-5" />
                    </div>
                    <div className="min-w-0 flex-1">
                        <h2 className="text-base font-bold text-slate-900">Identifiants de l’app</h2>
                        <p className="mt-0.5 text-sm font-medium text-slate-500">
                            Client ID et secret depuis Shopify Partners / Apps développement.
                        </p>
                    </div>
                </div>

                <form onSubmit={handleSaveCredentials} className="mt-6 space-y-4">
                    <label className="block">
                        <span className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Client ID
                        </span>
                        <input
                            type="text"
                            required
                            value={clientId}
                            onChange={(e) => setClientId(e.target.value)}
                            className={inputClass}
                        />
                    </label>
                    <label className="block">
                        <span className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Client secret
                        </span>
                        <input
                            type="password"
                            value={clientSecret}
                            onChange={(e) => setClientSecret(e.target.value)}
                            placeholder={status?.has_client_secret ? '•••••••• (laisser vide pour conserver)' : ''}
                            className={inputClass}
                        />
                    </label>
                    <label className="block">
                        <span className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Scopes
                        </span>
                        <input
                            type="text"
                            value={scopes}
                            onChange={(e) => setScopes(e.target.value)}
                            className={inputClass}
                        />
                    </label>
                    <button
                        type="submit"
                        disabled={savingCredentials}
                        className="inline-flex h-11 items-center rounded-xl bg-slate-900 px-5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:opacity-60"
                    >
                        {savingCredentials ? 'Enregistrement…' : 'Enregistrer'}
                    </button>
                </form>
            </section>

            <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-200/40 sm:p-7">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="flex items-start gap-3">
                        <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700">
                            <Store className="h-5 w-5" />
                        </div>
                        <div>
                            <h2 className="text-base font-bold text-slate-900">
                                {connected ? status.shop.shop_name || status.shop.shop_domain : 'Connecter une boutique'}
                            </h2>
                            <p className="mt-0.5 text-sm font-medium text-slate-500">
                                {connected
                                    ? status.shop.shop_domain
                                    : status?.configured
                                      ? "Autorisez Lav'Fast Flow via l’installation de l’app Shopify (OAuth)."
                                      : 'Enregistrez d’abord les identifiants de l’app ci-dessus.'}
                            </p>
                            {connected ? (
                                <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs font-medium text-slate-500">
                                    <span>
                                        Statut :{' '}
                                        <span className="font-semibold text-emerald-600">Connecté</span>
                                    </span>
                                    <span>
                                        Commandes :{' '}
                                        <Link to="/commandes" className="font-semibold text-blue-600 hover:text-blue-700">
                                            {status.shop.orders_count} (voir la liste)
                                        </Link>
                                    </span>
                                    <span>Dernière sync : {formatDate(status.shop.last_synced_at)}</span>
                                    <span>Commandes réconciliées : {formatDate(status.shop.orders_reconciled_at)}</span>
                                </div>
                            ) : null}
                        </div>
                    </div>

                    {connected ? (
                        <div className="flex flex-wrap gap-2">
                            <button
                                type="button"
                                onClick={handleReconcile}
                                disabled={syncing}
                                className="inline-flex h-10 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-bold text-white shadow-md shadow-blue-500/20 transition hover:bg-blue-700 disabled:opacity-60"
                            >
                                <RefreshCw className={`h-4 w-4 ${syncing ? 'animate-spin' : ''}`} />
                                {syncing ? 'Sync…' : 'Synchroniser maintenant'}
                            </button>
                            <button
                                type="button"
                                onClick={handleSync}
                                disabled={syncing}
                                className="inline-flex h-10 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-bold text-white shadow-md shadow-blue-500/20 transition hover:bg-blue-700 disabled:opacity-60"
                            >
                                <RefreshCw className={`h-4 w-4 ${syncing ? 'animate-spin' : ''}`} />
                                {syncing ? 'Sync…' : 'Importer les 100 dernières'}
                            </button>
                            <button
                                type="button"
                                onClick={handleRegisterWebhooks}
                                disabled={registering}
                                className="inline-flex h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-sm font-bold text-slate-700 transition hover:bg-slate-50 disabled:opacity-60"
                            >
                                {registering ? 'Enregistrement…' : 'Réenregistrer les webhooks'}
                            </button>
                            <button
                                type="button"
                                onClick={handleDisconnect}
                                disabled={disconnecting}
                                className="inline-flex h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-sm font-bold text-slate-700 transition hover:bg-slate-50 disabled:opacity-60"
                            >
                                <Unplug className="h-4 w-4" />
                                Déconnecter
                            </button>
                        </div>
                    ) : null}
                </div>

                {!connected && status?.configured ? (
                    <form onSubmit={handleConnect} className="mt-6 border-t border-slate-100 pt-5">
                        <label className="block max-w-md">
                            <span className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                                Domaine boutique
                            </span>
                            <input
                                type="text"
                                required
                                placeholder="votre-boutique.myshopify.com"
                                value={shopInput}
                                onChange={(e) => setShopInput(e.target.value)}
                                className={inputClass}
                            />
                        </label>
                        <button
                            type="submit"
                            disabled={connecting || !shopInput.trim()}
                            className="mt-4 inline-flex h-11 items-center gap-2 rounded-xl bg-blue-600 px-5 text-sm font-bold text-white shadow-md shadow-blue-500/20 transition hover:bg-blue-700 disabled:opacity-60"
                        >
                            <Link2 className="h-4 w-4" />
                            {connecting ? 'Redirection…' : 'Installer l’app Shopify'}
                        </button>
                    </form>
                ) : null}
            </section>

            {connected ? (
                <section id="journal" className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-200/40 sm:p-7">
                    <h2 className="text-base font-bold text-slate-900">Journal de synchronisation</h2>
                    <div className="mt-3 flex flex-wrap gap-2">
                        <select value={logFilter.direction} onChange={(e) => setLogFilter({ ...logFilter, direction: e.target.value })} className={inputClass + ' h-9 w-auto'}>
                            <option value="">Direction</option>
                            <option value="in">Entrant</option>
                            <option value="out">Sortant</option>
                        </select>
                        <select value={logFilter.status} onChange={(e) => setLogFilter({ ...logFilter, status: e.target.value })} className={inputClass + ' h-9 w-auto'}>
                            <option value="">Statut</option>
                            <option value="success">Succès</option>
                            <option value="failed">Échec</option>
                            <option value="pending">En attente</option>
                            <option value="echo">Écho</option>
                        </select>
                        <select value={logFilter.entity} onChange={(e) => setLogFilter({ ...logFilter, entity: e.target.value })} className={inputClass + ' h-9 w-auto'}>
                            <option value="">Entité</option>
                            <option value="order">Commande</option>
                            <option value="product">Produit</option>
                            <option value="fulfillment">Fulfillment</option>
                        </select>
                    </div>
                    <ul className="mt-4 divide-y divide-slate-100 text-sm">
                        {logs
                            .filter((row) => (!logFilter.direction || row.direction === logFilter.direction) && (!logFilter.status || row.status === logFilter.status) && (!logFilter.entity || row.entity_type === logFilter.entity))
                            .map((row) => (
                                <li key={row.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                    <span>
                                        <span className="font-semibold text-slate-800">{row.entity_type} · {row.action}</span>
                                        <span className="text-slate-500"> · {row.direction} · {row.status}</span>
                                        {row.error ? <span className="block text-xs text-rose-600">{row.error}</span> : null}
                                    </span>
                                    {row.status === 'failed' ? (
                                        <button type="button" onClick={() => retryLog(row.id)} className="text-sm font-semibold text-blue-600">
                                            Réessayer
                                        </button>
                                    ) : null}
                                </li>
                            ))}
                        {logs.length === 0 ? <li className="py-3 text-slate-500">Aucune ligne pour le moment.</li> : null}
                    </ul>
                </section>
            ) : null}
        </div>
    );
}
