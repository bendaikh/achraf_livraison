import { useCallback, useEffect, useState } from 'react';
import { Ban, MapPin, Pencil, Phone, Plus, RefreshCw, RotateCcw, Search, Star, Trash2, User } from 'lucide-react';
import api, { errorMessage } from '../lib/api';
import { Alert, Button, EmptyState, Input, PageHeader, Spinner } from '../components/ui';
import PartnerForm from '../components/partners/PartnerForm';

const CATEGORIES = [
    { value: 'all', label: 'Tous' },
    { value: 'ramassage', label: 'Partenaires de ramassage' },
    { value: 'depot', label: 'Partenaires de dépôt' },
];

const STATUSES = [
    { value: 'active', label: 'Actifs' },
    { value: 'inactive', label: 'Inactifs' },
    { value: 'all', label: 'Tous' },
];

const TYPE_BADGE = {
    ramassage: 'bg-sky-50 text-sky-700',
    depot: 'bg-violet-50 text-violet-700',
    both: 'bg-blue-50 text-blue-700',
};

function Pills({ items, value, onChange }) {
    return (
        <div className="flex flex-wrap gap-1.5">
            {items.map((item) => (
                <button
                    key={item.value}
                    type="button"
                    onClick={() => onChange(item.value)}
                    className={`rounded-lg px-3 py-1.5 text-xs font-semibold transition ${
                        value === item.value ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200/80'
                    }`}
                >
                    {item.label}
                </button>
            ))}
        </div>
    );
}

/** Paramètres → Partenaires logistiques (Ozon, Speedaf, Jumia…) used to prefill ramassage / dépôt missions. */
export default function LogisticsPartners() {
    const [partners, setPartners] = useState(null);
    const [q, setQ] = useState('');
    const [category, setCategory] = useState('all');
    const [status, setStatus] = useState('active');
    const [error, setError] = useState(null);
    const [msg, setMsg] = useState(null);
    const [editing, setEditing] = useState(null); // null closed, {} new, partner object edit

    const load = useCallback(async () => {
        try {
            const { data } = await api.get('/logistics-partners', {
                params: {
                    q: q || undefined,
                    category: category === 'all' ? undefined : category,
                    status: status === 'all' ? undefined : status,
                },
            });
            setPartners(data.data);
            setError(null);
        } catch (e) {
            setError(errorMessage(e));
        }
    }, [q, category, status]);

    useEffect(() => {
        const t = setTimeout(load, 250);
        return () => clearTimeout(t);
    }, [load]);

    async function act(fn, success) {
        setError(null);
        setMsg(null);
        try {
            await fn();
            setMsg(success);
            load();
        } catch (e) {
            setError(errorMessage(e));
        }
    }

    const toggleFavorite = (p) =>
        act(
            () => api.post(`/logistics-partners/${p.id}/favorite`, { is_favorite: !p.is_favorite }),
            p.is_favorite ? `${p.name} retiré des favoris.` : `${p.name} ajouté aux favoris.`,
        );
    const deactivate = (p) => {
        if (!window.confirm(`Désactiver ${p.name} ? Il n’apparaîtra plus dans les sélecteurs, l’historique des missions est conservé.`)) return;
        act(() => api.post(`/logistics-partners/${p.id}/deactivate`), `${p.name} désactivé.`);
    };
    const activate = (p) => act(() => api.post(`/logistics-partners/${p.id}/activate`), `${p.name} réactivé.`);
    const remove = (p) => {
        if (!window.confirm(`Supprimer définitivement ${p.name} ?`)) return;
        act(() => api.delete(`/logistics-partners/${p.id}`), `${p.name} supprimé.`);
    };

    return (
        <div className="space-y-4">
            <PageHeader
                title="Partenaires logistiques"
                subtitle="Partenaires de ramassage et de dépôt — sélectionnables lors de la création d’une mission."
                actions={
                    <>
                        <Button variant="secondary" onClick={load}>
                            <RefreshCw className="h-4 w-4" /> Actualiser
                        </Button>
                        <Button onClick={() => setEditing({})}>
                            <Plus className="h-4 w-4" /> Ajouter un partenaire
                        </Button>
                    </>
                }
            />
            <div className="flex flex-col gap-2 lg:flex-row lg:items-center">
                <div className="relative w-full max-w-md">
                    <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Nom, ville, téléphone, contact…" className="pl-9" />
                </div>
                <Pills items={CATEGORIES} value={category} onChange={setCategory} />
                <Pills items={STATUSES} value={status} onChange={setStatus} />
            </div>
            <Alert>{error}</Alert>
            <Alert type="success">{msg}</Alert>

            {!partners ? (
                <Spinner />
            ) : partners.length === 0 ? (
                <EmptyState>Aucun partenaire — ajoutez Ozon, Speedaf, Jumia… pour les sélectionner lors de la création d’une mission.</EmptyState>
            ) : (
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    {partners.map((p) => (
                        <article
                            key={p.id}
                            className={`flex flex-col rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm shadow-slate-200/40 ${p.is_active ? '' : 'opacity-70'}`}
                        >
                            <div className="flex items-start gap-2">
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h3 className="truncate font-bold text-slate-900">{p.name}</h3>
                                        <span className={`rounded-full px-2 py-0.5 text-[10px] font-bold ${TYPE_BADGE[p.type] || TYPE_BADGE.both}`}>
                                            {p.type_label}
                                        </span>
                                        <span
                                            className={`rounded-full px-2 py-0.5 text-[10px] font-bold ${p.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}
                                        >
                                            {p.is_active ? 'Actif' : 'Inactif'}
                                        </span>
                                    </div>
                                    <div className="mt-1 space-y-0.5 text-xs text-slate-500">
                                        {p.phone ? (
                                            <div className="inline-flex items-center gap-1">
                                                <Phone className="h-3 w-3" /> {p.phone}
                                            </div>
                                        ) : null}
                                        {p.city || p.address ? (
                                            <div className="flex items-start gap-1">
                                                <MapPin className="mt-0.5 h-3 w-3 shrink-0" />
                                                <span>{[p.address, p.city].filter(Boolean).join(', ')}</span>
                                            </div>
                                        ) : null}
                                        {p.contact_name ? (
                                            <div className="inline-flex items-center gap-1">
                                                <User className="h-3 w-3" /> {p.contact_name}
                                            </div>
                                        ) : null}
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => toggleFavorite(p)}
                                    title={p.is_favorite ? 'Retirer des favoris' : 'Marquer comme favori'}
                                    className="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg hover:bg-slate-100"
                                >
                                    <Star className={`h-4 w-4 ${p.is_favorite ? 'fill-amber-400 text-amber-400' : 'text-slate-300'}`} />
                                </button>
                            </div>
                            {p.note ? <p className="mt-2 rounded-xl bg-slate-50 px-2.5 py-2 text-xs text-slate-600">{p.note}</p> : null}
                            <div className="mt-2 text-[11px] font-medium text-slate-400">
                                {p.missions_count ? `${p.missions_count} mission${p.missions_count > 1 ? 's' : ''} dans l’historique` : 'Jamais utilisé'}
                            </div>
                            <div className="mt-3 flex flex-wrap gap-2 border-t border-slate-100 pt-3">
                                <Button size="sm" variant="secondary" onClick={() => setEditing(p)}>
                                    <Pencil className="h-3.5 w-3.5" /> Modifier
                                </Button>
                                {p.is_active ? (
                                    <Button size="sm" variant="secondary" onClick={() => deactivate(p)}>
                                        <Ban className="h-3.5 w-3.5" /> Désactiver
                                    </Button>
                                ) : (
                                    <Button size="sm" variant="secondary" onClick={() => activate(p)}>
                                        <RotateCcw className="h-3.5 w-3.5" /> Réactiver
                                    </Button>
                                )}
                                {!p.missions_count ? (
                                    <Button size="sm" variant="ghost" className="text-rose-600 hover:bg-rose-50" onClick={() => remove(p)}>
                                        <Trash2 className="h-3.5 w-3.5" /> Supprimer
                                    </Button>
                                ) : null}
                            </div>
                        </article>
                    ))}
                </div>
            )}

            <PartnerForm
                open={editing !== null}
                partner={editing && editing.id ? editing : null}
                defaultType={category === 'all' ? 'both' : category}
                onClose={() => setEditing(null)}
                onSaved={(p) => {
                    setEditing(null);
                    setMsg(`Partenaire ${p.name} enregistré.`);
                    load();
                }}
            />
        </div>
    );
}
