import { useCallback, useEffect, useState } from 'react';
import { MapPin, MessageCircle, Phone } from 'lucide-react';
import api, { errorMessage } from '../lib/api';
import { formatDateTime } from '../lib/format';
import { Alert, Button, Input, Spinner, Textarea } from '../components/ui';
import SavItems from '../components/sav/SavItems';
import { SavStatus } from '../components/sav/SavDetailDrawer';
import { telUrl, whatsappUrl } from './confirmationHelpers';

const PRIMARY = ['picked_up', 'exchanged', 'returning', 'en_route'];

function SavCard({ sav, onDone }) {
    const [pending, setPending] = useState(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    async function act(action, extra = {}) {
        setBusy(true);
        setError(null);
        try {
            await api.post(`/driver/sav/${sav.id}/action`, { action, ...extra });
            setPending(null);
            onDone();
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy(false);
        }
    }

    return (
        <article className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div className="flex items-start justify-between gap-2">
                <div>
                    <div className="flex items-center gap-1.5">
                        <span className={`rounded px-1.5 py-0.5 text-[11px] font-bold ${sav.type === 'echange' ? 'bg-violet-50 text-violet-700' : 'bg-orange-50 text-orange-700'}`}>{sav.type_label}</span>
                        <span className="text-sm font-bold text-slate-900">{sav.reference}</span>
                    </div>
                    <div className="text-[11px] text-slate-400">Commande {sav.order_reference}</div>
                </div>
                <SavStatus sav={sav} />
            </div>
            <div className="mt-2 text-sm">
                <div className="font-semibold text-slate-800">{sav.customer_name}</div>
                <div className="flex items-start gap-1 text-xs text-slate-500">
                    <MapPin className="mt-0.5 h-3 w-3 shrink-0" /> {[sav.address, sav.city].filter(Boolean).join(', ') || '—'}
                </div>
                {sav.postponed_until && sav.status === 'postponed' ? <div className="mt-1 text-xs font-semibold text-violet-700">Reportée au {formatDateTime(sav.postponed_until)}</div> : null}
            </div>
            <div className="mt-2 rounded-xl bg-slate-50 px-3 py-2">
                <SavItems sav={sav} />
            </div>
            {sav.sav_note || sav.reason ? (
                <p className="mt-2 text-xs text-slate-600">
                    <b>Motif :</b> {sav.reason}
                    {sav.sav_note ? (
                        <>
                            <br />
                            <b>Note SAV :</b> {sav.sav_note}
                        </>
                    ) : null}
                </p>
            ) : null}
            <Alert>{error}</Alert>
            <div className="mt-3 grid grid-cols-2 gap-2">
                <a href={telUrl(sav.phone) || '#'} className="inline-flex h-10 items-center justify-center gap-1.5 rounded-xl bg-blue-600 text-sm font-bold text-white">
                    <Phone className="h-4 w-4" /> Appeler
                </a>
                <a href={whatsappUrl(sav.phone) || '#'} target="_blank" rel="noreferrer" className="inline-flex h-10 items-center justify-center gap-1.5 rounded-xl bg-emerald-600 text-sm font-bold text-white">
                    <MessageCircle className="h-4 w-4" /> WhatsApp
                </a>
                {sav.actions.map((a) => (
                    <Button
                        key={a.key}
                        variant={PRIMARY.includes(a.key) ? 'primary' : 'secondary'}
                        className={PRIMARY.includes(a.key) && a.key !== 'en_route' ? 'col-span-2' : ''}
                        disabled={busy}
                        onClick={() => (['postpone', 'problem'].includes(a.key) ? setPending({ ...a, comment: '', postponed_until: '' }) : act(a.key))}
                    >
                        {a.label}
                    </Button>
                ))}
            </div>
            {pending ? (
                <div className="mt-2 space-y-2 rounded-xl border border-slate-200 p-3">
                    {pending.key === 'postpone' ? <Input type="datetime-local" value={pending.postponed_until} onChange={(e) => setPending({ ...pending, postponed_until: e.target.value })} aria-label="Date du report" /> : null}
                    <Textarea value={pending.comment} onChange={(e) => setPending({ ...pending, comment: e.target.value })} placeholder={pending.key === 'problem' ? 'Décrivez le problème' : 'Commentaire'} />
                    <div className="flex gap-2">
                        <Button size="sm" disabled={busy} onClick={() => act(pending.key, { comment: pending.comment, postponed_until: pending.postponed_until || null })}>
                            Valider : {pending.label}
                        </Button>
                        <Button size="sm" variant="ghost" onClick={() => setPending(null)}>
                            Annuler
                        </Button>
                    </div>
                </div>
            ) : null}
        </article>
    );
}

/** T7 — Espace livreur → Retours & échanges. */
export default function DriverSav() {
    const [filter, setFilter] = useState('active');
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const load = useCallback(
        () =>
            api
                .get('/driver/sav', { params: { filter } })
                .then(({ data: d }) => setData(d))
                .catch((e) => setError(errorMessage(e))),
        [filter],
    );
    useEffect(() => {
        load();
    }, [load]);

    return (
        <div className="mx-auto max-w-2xl space-y-4">
            <div>
                <h1 className="text-xl font-bold text-slate-900">Retours & échanges</h1>
                <p className="text-sm text-slate-500">Produits à récupérer et nouveaux produits à remettre. La réception au dépôt est validée par le responsable.</p>
            </div>
            <div className="flex gap-1.5">
                {[
                    ['active', 'À traiter'],
                    ['history', 'Historique'],
                ].map(([k, l]) => (
                    <button key={k} type="button" onClick={() => setFilter(k)} className={`rounded-lg px-3 py-1.5 text-xs font-semibold ${filter === k ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600'}`}>
                        {l} <span className="opacity-70">{data?.counts?.[k] ?? ''}</span>
                    </button>
                ))}
            </div>
            <Alert>{error}</Alert>
            {!data ? <Spinner /> : !data.data.length ? <p className="py-10 text-center text-sm text-slate-400">Aucun retour ni échange.</p> : data.data.map((s) => <SavCard key={s.id} sav={s} onDone={load} />)}
        </div>
    );
}
