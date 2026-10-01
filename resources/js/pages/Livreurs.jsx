import { useCallback, useEffect, useState } from 'react';
import { Bike, Plus, RefreshCw, Search, X } from 'lucide-react';
import { formatMoney } from './confirmationHelpers';

function DriverFormModal({ open, driver, busy, error, onClose, onSubmit }) {
    const isEdit = Boolean(driver?.id);
    const [name, setName] = useState('');
    const [phone, setPhone] = useState('');
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [isActive, setIsActive] = useState(true);

    useEffect(() => {
        if (!open) return;
        setName(driver?.name || '');
        setPhone(driver?.phone || '');
        setEmail(driver?.email || '');
        setPassword('');
        setIsActive(driver?.is_active ?? true);
    }, [open, driver]);

    if (!open) return null;

    return (
        <div className="fixed inset-0 z-[70] flex items-end justify-center bg-slate-900/50 p-0 sm:items-center sm:p-4">
            <button type="button" className="absolute inset-0 cursor-default" aria-label="Fermer" onClick={onClose} />
            <div className="relative z-10 w-full max-w-md rounded-t-2xl bg-white p-5 shadow-xl sm:rounded-2xl">
                <div className="mb-4 flex items-center justify-between gap-3">
                    <h3 className="text-base font-bold text-slate-900">
                        {isEdit ? 'Modifier le livreur' : 'Nouveau livreur'}
                    </h3>
                    <button
                        type="button"
                        onClick={onClose}
                        className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100"
                    >
                        <X className="h-4 w-4" />
                    </button>
                </div>

                {error ? (
                    <div className="mb-3 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-medium text-rose-700">
                        {error}
                    </div>
                ) : null}

                <div className="space-y-3">
                    <label className="block">
                        <span className="mb-1 block text-xs font-semibold uppercase text-slate-500">Nom</span>
                        <input
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm outline-none focus:border-blue-300 focus:bg-white focus:ring-4 focus:ring-blue-500/10"
                            required
                        />
                    </label>
                    <label className="block">
                        <span className="mb-1 block text-xs font-semibold uppercase text-slate-500">Téléphone</span>
                        <input
                            value={phone}
                            onChange={(e) => setPhone(e.target.value)}
                            className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm outline-none focus:border-blue-300 focus:bg-white focus:ring-4 focus:ring-blue-500/10"
                        />
                    </label>
                    <label className="block">
                        <span className="mb-1 block text-xs font-semibold uppercase text-slate-500">
                            Email de connexion
                        </span>
                        <input
                            type="email"
                            value={email}
                            onChange={(e) => setEmail(e.target.value)}
                            className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm outline-none focus:border-blue-300 focus:bg-white focus:ring-4 focus:ring-blue-500/10"
                            required
                        />
                    </label>
                    <label className="block">
                        <span className="mb-1 block text-xs font-semibold uppercase text-slate-500">
                            {isEdit ? 'Nouveau mot de passe (optionnel)' : 'Mot de passe'}
                        </span>
                        <input
                            type="password"
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm outline-none focus:border-blue-300 focus:bg-white focus:ring-4 focus:ring-blue-500/10"
                            required={!isEdit}
                            minLength={isEdit ? undefined : 8}
                        />
                    </label>
                    <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
                        <input
                            type="checkbox"
                            checked={isActive}
                            onChange={(e) => setIsActive(e.target.checked)}
                            className="h-4 w-4 rounded border-slate-300 text-blue-600"
                        />
                        Compte actif
                    </label>
                </div>

                <div className="mt-5 flex gap-2">
                    <button
                        type="button"
                        onClick={onClose}
                        className="h-10 flex-1 rounded-xl border border-slate-200 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                    >
                        Annuler
                    </button>
                    <button
                        type="button"
                        disabled={busy || !name.trim() || !email.trim() || (!isEdit && password.length < 8)}
                        onClick={() =>
                            onSubmit({
                                name: name.trim(),
                                phone: phone.trim() || null,
                                email: email.trim(),
                                password: password || undefined,
                                is_active: isActive,
                            })
                        }
                        className="h-10 flex-1 rounded-xl bg-blue-600 text-sm font-bold text-white hover:bg-blue-700 disabled:opacity-60"
                    >
                        {busy ? 'Enregistrement…' : 'Enregistrer'}
                    </button>
                </div>
            </div>
        </div>
    );
}

export default function Livreurs() {
    const [drivers, setDrivers] = useState([]);
    const [search, setSearch] = useState('');
    const [debouncedSearch, setDebouncedSearch] = useState('');
    const [activeFilter, setActiveFilter] = useState('all');
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [toast, setToast] = useState('');
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [busy, setBusy] = useState(false);
    const [formError, setFormError] = useState('');

    useEffect(() => {
        const t = setTimeout(() => setDebouncedSearch(search.trim()), 300);
        return () => clearTimeout(t);
    }, [search]);

    const load = useCallback(async () => {
        setLoading(true);
        setError('');
        try {
            const { data } = await window.axios.get('/api/drivers', {
                params: {
                    search: debouncedSearch || undefined,
                    active: activeFilter === 'all' ? undefined : activeFilter === 'active' ? '1' : '0',
                },
            });
            setDrivers(data.drivers || []);
        } catch (err) {
            setError(err.response?.data?.message || 'Impossible de charger les livreurs.');
            setDrivers([]);
        } finally {
            setLoading(false);
        }
    }, [debouncedSearch, activeFilter]);

    useEffect(() => {
        load();
    }, [load]);

    const openCreate = () => {
        setEditing(null);
        setFormError('');
        setFormOpen(true);
    };

    const openEdit = (driver) => {
        setEditing(driver);
        setFormError('');
        setFormOpen(true);
    };

    const submitForm = async (payload) => {
        setBusy(true);
        setFormError('');
        try {
            if (editing?.id) {
                const body = { ...payload };
                if (!body.password) delete body.password;
                const { data } = await window.axios.put(`/api/drivers/${editing.id}`, body);
                setToast(data.message || 'Livreur mis à jour.');
            } else {
                const { data } = await window.axios.post('/api/drivers', payload);
                setToast(data.message || 'Livreur créé.');
            }
            setFormOpen(false);
            window.setTimeout(() => setToast(''), 2500);
            await load();
        } catch (err) {
            setFormError(
                err.response?.data?.message ||
                    Object.values(err.response?.data?.errors || {})?.[0]?.[0] ||
                    'Enregistrement impossible.',
            );
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="space-y-4 sm:space-y-6">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">Livreurs</h1>
                    <p className="mt-1 text-sm font-medium text-slate-500">
                        Équipe locale Lavfast — missions, COD détenu et accès connexion.
                    </p>
                </div>
                <div className="flex gap-2">
                    <button
                        type="button"
                        onClick={load}
                        disabled={loading}
                        className="inline-flex h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-sm font-bold text-slate-700 shadow-sm hover:bg-slate-50 disabled:opacity-60"
                    >
                        <RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />
                        Actualiser
                    </button>
                    <button
                        type="button"
                        onClick={openCreate}
                        className="inline-flex h-10 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-bold text-white shadow-sm hover:bg-blue-700"
                    >
                        <Plus className="h-4 w-4" />
                        Ajouter
                    </button>
                </div>
            </div>

            {toast ? (
                <div className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">
                    {toast}
                </div>
            ) : null}

            <div className="rounded-2xl border border-slate-200/80 bg-white p-3 shadow-sm sm:p-4">
                <div className="relative">
                    <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <input
                        type="search"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Nom, téléphone, email…"
                        className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-9 pr-3 text-sm outline-none focus:border-blue-300 focus:bg-white focus:ring-4 focus:ring-blue-500/10"
                    />
                </div>
                <div className="mt-3 flex gap-1.5">
                    {[
                        { value: 'all', label: 'Tous' },
                        { value: 'active', label: 'Actifs' },
                        { value: 'inactive', label: 'Inactifs' },
                    ].map((item) => (
                        <button
                            key={item.value}
                            type="button"
                            onClick={() => setActiveFilter(item.value)}
                            className={`rounded-lg px-3 py-1.5 text-xs font-semibold transition ${
                                activeFilter === item.value
                                    ? 'bg-blue-600 text-white'
                                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200/80'
                            }`}
                        >
                            {item.label}
                        </button>
                    ))}
                </div>
            </div>

            {error ? (
                <div className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700">
                    {error}
                </div>
            ) : null}

            {loading && drivers.length === 0 ? (
                <div className="flex min-h-[240px] items-center justify-center rounded-2xl border border-slate-200/80 bg-white text-sm font-medium text-slate-500">
                    Chargement…
                </div>
            ) : drivers.length === 0 ? (
                <div className="flex min-h-[280px] flex-col items-center justify-center rounded-2xl border border-slate-200/80 bg-white px-6 py-12 text-center shadow-sm">
                    <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-500">
                        <Bike className="h-6 w-6" />
                    </div>
                    <p className="mt-4 text-sm font-semibold text-slate-800">Aucun livreur</p>
                    <p className="mt-1 max-w-sm text-sm font-medium text-slate-500">
                        Créez un livreur pour lui affecter des commandes confirmées.
                    </p>
                    <button
                        type="button"
                        onClick={openCreate}
                        className="mt-4 inline-flex h-10 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-bold text-white"
                    >
                        <Plus className="h-4 w-4" />
                        Ajouter un livreur
                    </button>
                </div>
            ) : (
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    {drivers.map((driver) => (
                        <button
                            key={driver.id}
                            type="button"
                            onClick={() => openEdit(driver)}
                            className="rounded-2xl border border-slate-200/80 bg-white p-4 text-left shadow-sm transition hover:border-blue-200 hover:shadow-md"
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <p className="text-sm font-bold text-slate-900">{driver.name}</p>
                                    <p className="mt-0.5 text-xs font-medium text-slate-500">
                                        {driver.phone || 'Sans téléphone'}
                                    </p>
                                    <p className="mt-0.5 text-xs font-medium text-slate-400">{driver.email}</p>
                                </div>
                                <span
                                    className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${
                                        driver.is_active
                                            ? 'bg-emerald-50 text-emerald-700'
                                            : 'bg-slate-100 text-slate-500'
                                    }`}
                                >
                                    {driver.is_active ? 'Actif' : 'Inactif'}
                                </span>
                            </div>
                            <div className="mt-4 grid grid-cols-2 gap-2 text-center">
                                <div className="rounded-xl bg-slate-50 px-2 py-2">
                                    <p className="text-base font-bold text-slate-900">{driver.orders_assigned}</p>
                                    <p className="text-[10px] font-semibold uppercase text-slate-400">Attribuées</p>
                                </div>
                                <div className="rounded-xl bg-slate-50 px-2 py-2">
                                    <p className="text-base font-bold text-slate-900">{driver.orders_in_progress}</p>
                                    <p className="text-[10px] font-semibold uppercase text-slate-400">En cours</p>
                                </div>
                                <div className="rounded-xl bg-slate-50 px-2 py-2">
                                    <p className="text-base font-bold text-slate-900">{driver.orders_delivered}</p>
                                    <p className="text-[10px] font-semibold uppercase text-slate-400">Livrées</p>
                                </div>
                                <div className="rounded-xl bg-amber-50 px-2 py-2">
                                    <p className="text-base font-bold text-amber-800">
                                        {formatMoney(driver.cod_held, 'MAD')}
                                    </p>
                                    <p className="text-[10px] font-semibold uppercase text-amber-600/80">COD détenu</p>
                                </div>
                            </div>
                        </button>
                    ))}
                </div>
            )}

            <DriverFormModal
                open={formOpen}
                driver={editing}
                busy={busy}
                error={formError}
                onClose={() => setFormOpen(false)}
                onSubmit={submitForm}
            />
        </div>
    );
}
