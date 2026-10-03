import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api, { errorMessage } from '../../lib/api';
import { formatDateTime, formatDH } from '../../lib/format';
import { useAuth } from '../../contexts/AuthContext';
import { Alert, Button, Drawer, Field, Input, Select, Spinner, Textarea } from '../ui';
import SavItems from './SavItems';
import SavTimeline from './SavTimeline';

export function SavStatus({ sav }) {
    return (
        <span className="rounded-md px-1.5 py-0.5 text-[11px] font-bold" style={{ background: `${sav.status_color}18`, color: sav.status_color }}>
            {sav.status_label}
        </span>
    );
}

/** Back-office detail: snapshot, items custody, assign / reassign, admin actions, timeline. */
export default function SavDetailDrawer({ id, onClose, onChanged }) {
    const { can } = useAuth();
    const [sav, setSav] = useState(null);
    const [drivers, setDrivers] = useState([]);
    const [driverId, setDriverId] = useState('');
    const [pending, setPending] = useState(null); // action needing comment/date
    const [error, setError] = useState(null);
    const [msg, setMsg] = useState(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (!id) return;
        setSav(null);
        setMsg(null);
        setError(null);
        api.get(`/sav/${id}`).then(({ data }) => {
            setSav(data.data);
            setDriverId(data.data.driver_id ? String(data.data.driver_id) : '');
        });
        api.get('/sav/meta').then(({ data }) => setDrivers(data.drivers));
    }, [id]);

    async function run(fn) {
        setBusy(true);
        setError(null);
        try {
            const { data } = await fn();
            setSav(data.data);
            setMsg(data.message);
            setPending(null);
            onChanged?.();
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy(false);
        }
    }

    const doAction = (a) => {
        if (['postpone', 'problem', 'cancel'].includes(a.key)) return setPending({ ...a, comment: '', postponed_until: '' });
        if (['receive', 'close'].includes(a.key) && !can('sav.depot')) return null;
        return run(() => api.post(`/sav/${id}/action`, { action: a.key }));
    };

    return (
        <Drawer open={!!id} onClose={onClose} wide title={sav ? `${sav.type_label} ${sav.reference}` : 'Retour / échange'}>
            {!sav ? (
                <Spinner />
            ) : (
                <div className="space-y-4">
                    <Alert type="success">{msg}</Alert>
                    <Alert>{error}</Alert>
                    <div className="flex flex-wrap items-center gap-2">
                        <SavStatus sav={sav} />
                        <span className="text-xs text-slate-500">
                            Commande{' '}
                            <Link to={`/commandes/${sav.order_id}`} className="font-semibold text-blue-700 hover:underline">
                                {sav.order_reference}
                            </Link>{' '}
                            · créée le {formatDateTime(sav.created_at)}
                        </span>
                    </div>
                    <div className="grid grid-cols-2 gap-x-4 gap-y-1.5 rounded-xl bg-slate-50 p-3 text-xs text-slate-600">
                        <span>
                            <b className="text-slate-800">{sav.customer_name}</b> · {sav.phone}
                        </span>
                        <span>{[sav.address, sav.city].filter(Boolean).join(', ')}</span>
                        <span>Motif : <b className="text-slate-800">{sav.reason}</b>{sav.comment ? ` — ${sav.comment}` : ''}</span>
                        <span>Montant commande : {formatDH(sav.amount_paid)}</span>
                        <span>Livrée par {sav.original_driver_name || '—'} le {formatDateTime(sav.original_delivered_at)}</span>
                        <span>Frais livreur : {sav.driver_fee !== null ? formatDH(sav.driver_fee) : '—'}</span>
                        {sav.sav_note ? <span className="col-span-2">Note SAV : {sav.sav_note}</span> : null}
                    </div>
                    <SavItems sav={sav} />

                    {sav.can_assign ? (
                        <div className="flex items-end gap-2">
                            <Field label={sav.driver_id ? 'Réaffecter à un livreur' : 'Affecter à un livreur'} className="flex-1">
                                <Select value={driverId} onChange={(e) => setDriverId(e.target.value)}>
                                    <option value="">Choisir…</option>
                                    {drivers.map((d) => (
                                        <option key={d.id} value={d.id}>
                                            {d.name} — {formatDH(sav.type === 'echange' ? d.tariff_echange : d.tariff_retour)}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <Button disabled={!driverId || busy || String(sav.driver_id) === driverId} onClick={() => run(() => api.post(`/sav/${id}/assign`, { driver_id: Number(driverId) }))}>
                                Affecter
                            </Button>
                        </div>
                    ) : sav.driver_name ? (
                        <p className="text-sm text-slate-600">
                            Livreur : <b>{sav.driver_name}</b>
                        </p>
                    ) : null}

                    {sav.actions.length ? (
                        <div className="flex flex-wrap gap-2">
                            {sav.actions.map((a) => (
                                <Button
                                    key={a.key}
                                    size="sm"
                                    variant={a.key === 'cancel' ? 'ghost' : ['receive', 'close'].includes(a.key) ? 'primary' : 'secondary'}
                                    disabled={busy || (['receive', 'close'].includes(a.key) && !can('sav.depot'))}
                                    onClick={() => doAction(a)}
                                >
                                    {a.label}
                                </Button>
                            ))}
                        </div>
                    ) : null}
                    {pending ? (
                        <div className="space-y-2 rounded-xl border border-slate-200 p-3">
                            <div className="text-sm font-bold text-slate-800">{pending.label}</div>
                            {pending.key === 'postpone' ? (
                                <Field label="Nouvelle date">
                                    <Input type="datetime-local" value={pending.postponed_until} onChange={(e) => setPending({ ...pending, postponed_until: e.target.value })} />
                                </Field>
                            ) : null}
                            <Textarea value={pending.comment} onChange={(e) => setPending({ ...pending, comment: e.target.value })} placeholder="Commentaire" />
                            <div className="flex gap-2">
                                <Button size="sm" disabled={busy} onClick={() => run(() => api.post(`/sav/${id}/action`, { action: pending.key, comment: pending.comment, postponed_until: pending.postponed_until || null }))}>
                                    Valider
                                </Button>
                                <Button size="sm" variant="ghost" onClick={() => setPending(null)}>
                                    Annuler
                                </Button>
                            </div>
                        </div>
                    ) : null}

                    <div>
                        <h3 className="mb-2 text-xs font-bold uppercase tracking-wide text-slate-400">Historique</h3>
                        <SavTimeline history={sav.history} />
                    </div>
                </div>
            )}
        </Drawer>
    );
}
