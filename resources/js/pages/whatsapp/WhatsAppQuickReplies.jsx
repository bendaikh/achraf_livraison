import { useCallback, useEffect, useState } from 'react';
import { Plus, Pencil, Trash2 } from 'lucide-react';

const inputClass =
    'h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm outline-none transition focus:border-emerald-300 focus:bg-white focus:ring-4 focus:ring-emerald-500/10';

const emptyForm = { title: '', body: '', category: '', is_active: true, sort_order: 0 };

export default function WhatsAppQuickReplies() {
    const [replies, setReplies] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [message, setMessage] = useState('');
    const [form, setForm] = useState(emptyForm);
    const [editingId, setEditingId] = useState(null);
    const [saving, setSaving] = useState(false);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const { data } = await window.axios.get('/api/whatsapp/quick-replies');
            setReplies(data.quick_replies || []);
        } catch (err) {
            setError(err.response?.data?.message || 'Impossible de charger les réponses rapides.');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    async function handleSubmit(event) {
        event.preventDefault();
        setSaving(true);
        setError('');
        try {
            if (editingId) {
                const { data } = await window.axios.put(`/api/whatsapp/quick-replies/${editingId}`, form);
                setMessage(data.message);
            } else {
                const { data } = await window.axios.post('/api/whatsapp/quick-replies', form);
                setMessage(data.message);
            }
            setForm(emptyForm);
            setEditingId(null);
            await load();
        } catch (err) {
            setError(err.response?.data?.message || 'Enregistrement impossible.');
        } finally {
            setSaving(false);
        }
    }

    function startEdit(reply) {
        setEditingId(reply.id);
        setForm({
            title: reply.title,
            body: reply.body,
            category: reply.category || '',
            is_active: reply.is_active,
            sort_order: reply.sort_order || 0,
        });
    }

    async function handleDelete(reply) {
        if (!window.confirm(`Supprimer « ${reply.title} » ?`)) return;
        try {
            await window.axios.delete(`/api/whatsapp/quick-replies/${reply.id}`);
            setMessage('Réponse rapide supprimée.');
            await load();
        } catch (err) {
            setError(err.response?.data?.message || 'Suppression impossible.');
        }
    }

    async function toggleActive(reply) {
        try {
            await window.axios.put(`/api/whatsapp/quick-replies/${reply.id}`, {
                is_active: !reply.is_active,
            });
            await load();
        } catch (err) {
            setError(err.response?.data?.message || 'Mise à jour impossible.');
        }
    }

    return (
        <div className="space-y-4 sm:space-y-6">
            <div>
                <h1 className="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">Réponses rapides</h1>
                <p className="mt-1 text-sm font-medium text-slate-500">
                    Messages préenregistrés modifiables — jamais codés en dur dans l’application.
                </p>
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

            <form onSubmit={handleSubmit} className="space-y-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                <h2 className="text-sm font-bold text-slate-900">
                    {editingId ? 'Modifier la réponse' : 'Nouvelle réponse rapide'}
                </h2>
                <div className="grid gap-3 sm:grid-cols-2">
                    <label className="block text-xs font-bold text-slate-500">
                        Titre
                        <input
                            className={`${inputClass} mt-1`}
                            value={form.title}
                            onChange={(e) => setForm((f) => ({ ...f, title: e.target.value }))}
                            required
                        />
                    </label>
                    <label className="block text-xs font-bold text-slate-500">
                        Catégorie
                        <input
                            className={`${inputClass} mt-1`}
                            value={form.category}
                            onChange={(e) => setForm((f) => ({ ...f, category: e.target.value }))}
                            placeholder="ex. Confirmation"
                        />
                    </label>
                    <label className="block text-xs font-bold text-slate-500 sm:col-span-2">
                        Message
                        <textarea
                            className={`${inputClass} mt-1 h-28 py-2`}
                            value={form.body}
                            onChange={(e) => setForm((f) => ({ ...f, body: e.target.value }))}
                            required
                        />
                    </label>
                </div>
                <div className="flex flex-wrap gap-2">
                    <button
                        type="submit"
                        disabled={saving}
                        className="inline-flex h-10 items-center gap-2 rounded-xl bg-emerald-600 px-4 text-sm font-bold text-white disabled:opacity-60"
                    >
                        <Plus className="h-4 w-4" />
                        {editingId ? 'Enregistrer' : 'Ajouter'}
                    </button>
                    {editingId ? (
                        <button
                            type="button"
                            onClick={() => {
                                setEditingId(null);
                                setForm(emptyForm);
                            }}
                            className="inline-flex h-10 items-center rounded-xl border border-slate-200 px-4 text-sm font-bold text-slate-600"
                        >
                            Annuler
                        </button>
                    ) : null}
                </div>
            </form>

            <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                {loading ? (
                    <p className="px-4 py-10 text-center text-sm text-slate-500">Chargement…</p>
                ) : replies.length === 0 ? (
                    <p className="px-4 py-10 text-center text-sm text-slate-500">Aucune réponse rapide.</p>
                ) : (
                    <div className="divide-y divide-slate-100">
                        {replies.map((reply) => (
                            <div
                                key={reply.id}
                                className="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-start sm:justify-between sm:px-5"
                            >
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <p className="text-sm font-bold text-slate-900">{reply.title}</p>
                                        {reply.category ? (
                                            <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-600">
                                                {reply.category}
                                            </span>
                                        ) : null}
                                        <span
                                            className={`rounded-full px-2 py-0.5 text-[11px] font-bold ${reply.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}
                                        >
                                            {reply.is_active ? 'Active' : 'Désactivée'}
                                        </span>
                                    </div>
                                    <p className="mt-1 whitespace-pre-wrap text-sm text-slate-600">{reply.body}</p>
                                </div>
                                <div className="flex shrink-0 gap-2">
                                    <button
                                        type="button"
                                        onClick={() => toggleActive(reply)}
                                        className="h-9 rounded-lg border border-slate-200 px-3 text-xs font-bold text-slate-600"
                                    >
                                        {reply.is_active ? 'Désactiver' : 'Activer'}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => startEdit(reply)}
                                        className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 text-slate-600"
                                    >
                                        <Pencil className="h-4 w-4" />
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => handleDelete(reply)}
                                        className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-rose-200 bg-rose-50 text-rose-700"
                                    >
                                        <Trash2 className="h-4 w-4" />
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
