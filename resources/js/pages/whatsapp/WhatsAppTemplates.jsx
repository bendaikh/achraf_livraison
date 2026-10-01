import { useCallback, useEffect, useState } from 'react';
import { Plus, RefreshCw } from 'lucide-react';
import { statusLabel } from './helpers';

const inputClass =
    'h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm outline-none transition focus:border-emerald-300 focus:bg-white focus:ring-4 focus:ring-emerald-500/10';

export default function WhatsAppTemplates() {
    const [templates, setTemplates] = useState([]);
    const [accounts, setAccounts] = useState([]);
    const [loading, setLoading] = useState(true);
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');
    const [showForm, setShowForm] = useState(false);
    const [saving, setSaving] = useState(false);
    const [form, setForm] = useState({
        whatsapp_account_id: '',
        name: '',
        language: 'fr',
        category: 'UTILITY',
        header: '',
        body: '',
        footer: '',
    });

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const [tplRes, accRes] = await Promise.all([
                window.axios.get('/api/whatsapp/templates'),
                window.axios.get('/api/whatsapp/accounts'),
            ]);
            setTemplates(tplRes.data.templates || []);
            setAccounts(accRes.data.accounts || []);
            if (!form.whatsapp_account_id && accRes.data.accounts?.[0]) {
                setForm((f) => ({ ...f, whatsapp_account_id: String(accRes.data.accounts[0].id) }));
            }
        } catch (err) {
            setError(err.response?.data?.message || 'Impossible de charger les templates.');
        } finally {
            setLoading(false);
        }
    }, [form.whatsapp_account_id]);

    useEffect(() => {
        load();
    }, []); // eslint-disable-line react-hooks/exhaustive-deps

    async function handleSync() {
        setError('');
        try {
            const { data } = await window.axios.post('/api/whatsapp/templates/sync');
            setMessage(data.message);
            await load();
        } catch (err) {
            setError(err.response?.data?.message || 'Synchronisation impossible.');
        }
    }

    async function handleCreate(event) {
        event.preventDefault();
        setSaving(true);
        setError('');
        try {
            const { data } = await window.axios.post('/api/whatsapp/templates', {
                ...form,
                whatsapp_account_id: Number(form.whatsapp_account_id),
                header: form.header || null,
                footer: form.footer || null,
            });
            setMessage(data.message);
            setShowForm(false);
            setForm((f) => ({ ...f, name: '', header: '', body: '', footer: '' }));
            await load();
        } catch (err) {
            setError(err.response?.data?.message || 'Création impossible.');
        } finally {
            setSaving(false);
        }
    }

    const statusTone = {
        APPROVED: 'bg-emerald-50 text-emerald-700 ring-emerald-600/15',
        PENDING: 'bg-amber-50 text-amber-700 ring-amber-600/15',
        REJECTED: 'bg-rose-50 text-rose-700 ring-rose-600/15',
    };

    return (
        <div className="space-y-4 sm:space-y-6">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">Templates</h1>
                    <p className="mt-1 text-sm font-medium text-slate-500">
                        Templates Meta synchronisés. Un template n’est jamais considéré comme approuvé avant validation Meta.
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <button
                        type="button"
                        onClick={handleSync}
                        className="inline-flex h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-sm font-bold text-slate-700 shadow-sm"
                    >
                        <RefreshCw className="h-4 w-4" />
                        Synchroniser
                    </button>
                    <button
                        type="button"
                        onClick={() => setShowForm(true)}
                        className="inline-flex h-10 items-center gap-2 rounded-xl bg-emerald-600 px-4 text-sm font-bold text-white shadow-sm"
                    >
                        <Plus className="h-4 w-4" />
                        Nouveau template
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

            {showForm ? (
                <form onSubmit={handleCreate} className="space-y-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <label className="block text-xs font-bold text-slate-500">
                            Numéro / WABA
                            <select
                                className={`${inputClass} mt-1`}
                                value={form.whatsapp_account_id}
                                onChange={(e) => setForm((f) => ({ ...f, whatsapp_account_id: e.target.value }))}
                                required
                            >
                                {accounts.map((a) => (
                                    <option key={a.id} value={a.id}>
                                        {a.name} ({a.display_phone_number || a.phone_number_id})
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="block text-xs font-bold text-slate-500">
                            Nom (a-z, 0-9, _)
                            <input
                                className={`${inputClass} mt-1`}
                                value={form.name}
                                onChange={(e) => setForm((f) => ({ ...f, name: e.target.value.toLowerCase() }))}
                                required
                                pattern="[a-z0-9_]+"
                            />
                        </label>
                        <label className="block text-xs font-bold text-slate-500">
                            Langue
                            <input
                                className={`${inputClass} mt-1`}
                                value={form.language}
                                onChange={(e) => setForm((f) => ({ ...f, language: e.target.value }))}
                                required
                            />
                        </label>
                        <label className="block text-xs font-bold text-slate-500">
                            Catégorie
                            <select
                                className={`${inputClass} mt-1`}
                                value={form.category}
                                onChange={(e) => setForm((f) => ({ ...f, category: e.target.value }))}
                            >
                                <option value="UTILITY">UTILITY</option>
                                <option value="MARKETING">MARKETING</option>
                                <option value="AUTHENTICATION">AUTHENTICATION</option>
                            </select>
                        </label>
                        <label className="block text-xs font-bold text-slate-500 sm:col-span-2">
                            Header (optionnel)
                            <input
                                className={`${inputClass} mt-1`}
                                value={form.header}
                                onChange={(e) => setForm((f) => ({ ...f, header: e.target.value }))}
                            />
                        </label>
                        <label className="block text-xs font-bold text-slate-500 sm:col-span-2">
                            Corps (variables {'{{1}}'}, {'{{2}}'}…)
                            <textarea
                                className={`${inputClass} mt-1 h-28 py-2`}
                                value={form.body}
                                onChange={(e) => setForm((f) => ({ ...f, body: e.target.value }))}
                                required
                            />
                        </label>
                        <label className="block text-xs font-bold text-slate-500 sm:col-span-2">
                            Footer (optionnel)
                            <input
                                className={`${inputClass} mt-1`}
                                value={form.footer}
                                onChange={(e) => setForm((f) => ({ ...f, footer: e.target.value }))}
                            />
                        </label>
                    </div>
                    <div className="flex gap-2">
                        <button
                            type="submit"
                            disabled={saving}
                            className="inline-flex h-10 items-center rounded-xl bg-emerald-600 px-4 text-sm font-bold text-white disabled:opacity-60"
                        >
                            Soumettre à Meta
                        </button>
                        <button
                            type="button"
                            onClick={() => setShowForm(false)}
                            className="inline-flex h-10 items-center rounded-xl border border-slate-200 px-4 text-sm font-bold text-slate-600"
                        >
                            Annuler
                        </button>
                    </div>
                </form>
            ) : null}

            <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                {loading ? (
                    <p className="px-4 py-10 text-center text-sm text-slate-500">Chargement…</p>
                ) : templates.length === 0 ? (
                    <p className="px-4 py-10 text-center text-sm text-slate-500">
                        Aucun template. Synchronisez depuis Meta ou créez-en un.
                    </p>
                ) : (
                    <div className="divide-y divide-slate-100">
                        {templates.map((t) => (
                            <div key={t.id} className="px-4 py-4 sm:px-5">
                                <div className="flex flex-wrap items-center gap-2">
                                    <p className="text-sm font-bold text-slate-900">{t.name}</p>
                                    <span
                                        className={`inline-flex rounded-full px-2 py-0.5 text-[11px] font-bold ring-1 ring-inset ${statusTone[t.status] || 'bg-slate-100 text-slate-600 ring-slate-500/10'}`}
                                    >
                                        {statusLabel(t.status)}
                                    </span>
                                    <span className="text-xs font-medium text-slate-500">
                                        {t.language} · {t.category}
                                    </span>
                                </div>
                                <p className="mt-2 whitespace-pre-wrap text-sm text-slate-700">{t.body_text}</p>
                                <p className="mt-2 text-xs font-medium text-slate-500">
                                    Variables : {t.variables_count} ·{' '}
                                    {t.account
                                        ? `${t.account.name} (${t.account.display_phone_number || '—'})`
                                        : `WABA ${t.waba_id || '—'}`}
                                </p>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}
