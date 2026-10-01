import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api, { errorMessage } from '../../lib/api';
import { useMeta } from '../../context/MetaContext';
import { formatDH, formatDate, formatDateTime } from '../../lib/format';
import { Alert, Drawer, Field, Select, Spinner } from '../ui';
import { ColorBadge } from '../ui/Badge';

export default function MissionDetail({ missionId, onClose, onChanged }) {
    const meta = useMeta();
    const [mission, setMission] = useState(null);
    const [error, setError] = useState(null);

    useEffect(() => {
        setMission(null);
        setError(null);
        if (!missionId) return;
        api.get(`/missions/${missionId}`)
            .then(({ data }) => setMission(data.data))
            .catch((e) => setError(errorMessage(e)));
    }, [missionId]);

    async function assign(driverId) {
        setError(null);
        try {
            const { data } = await api.put(`/missions/${missionId}`, { driver_id: driverId ? Number(driverId) : null });
            const fresh = await api.get(`/missions/${data.data.id}`);
            setMission(fresh.data.data);
            onChanged?.();
        } catch (e) {
            setError(errorMessage(e));
        }
    }

    const row = (label, value) => (
        <div>
            <dt className="text-[11px] font-semibold uppercase text-slate-400">{label}</dt>
            <dd className="text-sm font-semibold text-slate-800">{value || '—'}</dd>
        </div>
    );

    return (
        <Drawer open={!!missionId} onClose={onClose} title={mission ? `${mission.reference} · ${meta.missionTypeMap[mission.type]?.label}` : 'Mission'}>
            {!mission ? (
                error ? <Alert>{error}</Alert> : <Spinner />
            ) : (
                <div className="space-y-4">
                    <ColorBadge color={meta.missionStatusMap[mission.status]?.color} label={meta.missionStatusMap[mission.status]?.label} />
                    <dl className="grid grid-cols-2 gap-3">
                        {row('Contact / destination', mission.contact_name)}
                        {row('Téléphone', mission.phone)}
                        {row('Adresse', mission.address)}
                        {row('Ville', mission.city)}
                        {row('Articles', mission.items_description ? `${mission.quantity} × ${mission.items_description}` : null)}
                        {row('Date / créneau', `${formatDate(mission.scheduled_date)} ${mission.time_slot || ''}`)}
                        {row('Tarif livreur (figé)', mission.driver_price !== null ? formatDH(mission.driver_price) : null)}
                        {row('Montant', mission.cash_amount ? `${formatDH(mission.cash_amount)} (${mission.cash_direction === 'remit' ? 'à remettre' : 'à récupérer'})` : null)}
                        {mission.order_id ? row('Commande', <Link to={`/commandes/${mission.order_id}`} className="text-blue-600">{mission.order_reference}</Link>) : null}
                        {row('Clôture', mission.closing_id ? `Clôturée (#${mission.closing_id})` : 'Non clôturée')}
                    </dl>
                    {mission.note ? <p className="rounded-xl bg-slate-50 p-3 text-sm text-slate-600">{mission.note}</p> : null}
                    <Field label="Livreur" hint="Le tarif est figé au moment de l’attribution.">
                        <Select value={mission.driver_id || ''} onChange={(e) => assign(e.target.value)} disabled={mission.status === 'terminee' || !!mission.closing_id}>
                            <option value="">— Non affecté —</option>
                            {meta.drivers.map((d) => (
                                <option key={d.id} value={d.id}>
                                    {d.name}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Alert>{error}</Alert>
                    <div>
                        <h3 className="mb-2 text-sm font-bold text-slate-800">Historique</h3>
                        <ol className="space-y-2">
                            {(mission.histories || []).map((h) => (
                                <li key={h.id} className="flex justify-between gap-2 rounded-lg bg-slate-50 px-3 py-2 text-xs">
                                    <span className="font-medium text-slate-700">
                                        {h.label}
                                        {h.note ? <span className="text-slate-400"> · {h.note}</span> : null}
                                    </span>
                                    <span className="shrink-0 text-slate-400">{formatDateTime(h.created_at)}</span>
                                </li>
                            ))}
                        </ol>
                    </div>
                </div>
            )}
        </Drawer>
    );
}
