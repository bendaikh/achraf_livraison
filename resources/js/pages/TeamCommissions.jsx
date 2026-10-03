import { useCallback, useEffect, useState } from 'react';
import { BadgeCheck, CalendarPlus, Wallet, XCircle } from 'lucide-react';
import api, { errorMessage } from '../lib/api';
import { formatDH } from '../lib/format';
import { useMeta } from '../context/MetaContext';
import { Alert, Button, Card, PageHeader, Select, Spinner } from '../components/ui';
import PeriodFilter from '../components/team/PeriodFilter';
import CommissionLines from '../components/team/CommissionLines';

const STATES = [
    ['', 'Tous les états'],
    ['pending', 'En attente'],
    ['validated', 'Validées'],
    ['paid', 'Payées'],
    ['cancelled', 'Annulées'],
];

/** Équipe → Commissions: En attente → Validée → Payée (admin), per agent and period. */
export default function TeamCommissions() {
    const meta = useMeta();
    const [filter, setFilter] = useState({ period: 'month', state: '', user_id: '' });
    const [data, setData] = useState(null);
    const [selected, setSelected] = useState([]);
    const [error, setError] = useState(null);
    const [msg, setMsg] = useState(null);
    const [busy, setBusy] = useState(false);

    const load = useCallback(async () => {
        if (filter.period === 'custom' && (!filter.from || !filter.to)) return;
        try {
            const { data: d } = await api.get('/team/commissions', { params: Object.fromEntries(Object.entries(filter).filter(([, v]) => v)) });
            setData(d);
            setSelected([]);
        } catch (e) {
            setError(errorMessage(e));
        }
    }, [filter]);

    useEffect(() => {
        load();
    }, [load]);

    async function transition(action) {
        if (action === 'cancel' && !window.confirm(`Annuler ${selected.length} commission(s) ?`)) return;
        setBusy(true);
        setError(null);
        try {
            const { data: d } = await api.post('/team/commissions/transition', { ids: selected, action });
            setMsg(d.message);
            await load();
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy(false);
        }
    }

    async function monthly() {
        setBusy(true);
        try {
            const { data: d } = await api.post('/team/commissions/monthly', {});
            setMsg(d.message);
            await load();
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy(false);
        }
    }

    const totals = data?.totals || {};
    return (
        <div className="space-y-4">
            <PageHeader
                title="Commissions des agents"
                subtitle="En attente → Validée → Payée. Montants figés à la génération."
                actions={
                    data?.can_manage ? (
                        <Button variant="secondary" onClick={monthly} disabled={busy} title="Génère les fixes mensuels du mois précédent (fait aussi automatiquement le 1er du mois)">
                            <CalendarPlus className="h-4 w-4" /> Fixes mensuels
                        </Button>
                    ) : null
                }
            />
            <Alert type="success">{msg}</Alert>
            <Alert>{error}</Alert>
            <Card bodyClassName="p-3 sm:p-4">
                <div className="flex flex-wrap items-center gap-2">
                    <PeriodFilter value={filter} onChange={setFilter} />
                    <Select value={filter.state} onChange={(e) => setFilter({ ...filter, state: e.target.value })} className="max-w-44" aria-label="État">
                        {STATES.map(([v, l]) => (
                            <option key={v} value={v}>
                                {l}
                            </option>
                        ))}
                    </Select>
                    {data?.can_manage ? (
                        <Select value={filter.user_id} onChange={(e) => setFilter({ ...filter, user_id: e.target.value })} className="max-w-48" aria-label="Agent">
                            <option value="">Tous les agents</option>
                            {(meta.users || []).map((u) => (
                                <option key={u.id} value={u.id}>
                                    {u.name}
                                </option>
                            ))}
                        </Select>
                    ) : null}
                </div>
                <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                    {STATES.slice(1).map(([k, l]) => (
                        <div key={k} className="rounded-xl bg-slate-50 px-3 py-2">
                            <div className="text-[11px] font-semibold uppercase text-slate-400">{l}</div>
                            <div className="text-base font-bold text-slate-800">{formatDH(totals[k] || 0)}</div>
                        </div>
                    ))}
                </div>
            </Card>
            {data?.can_manage && selected.length ? (
                <div className="sticky top-16 z-20 flex flex-wrap items-center justify-between gap-2 rounded-2xl border border-blue-200 bg-blue-50 px-4 py-2.5 text-sm shadow-sm">
                    <span className="font-semibold text-blue-800">{selected.length} ligne(s) sélectionnée(s)</span>
                    <div className="flex gap-2">
                        <Button size="sm" onClick={() => transition('validate')} disabled={busy}>
                            <BadgeCheck className="h-3.5 w-3.5" /> Valider
                        </Button>
                        <Button size="sm" variant="secondary" onClick={() => transition('pay')} disabled={busy}>
                            <Wallet className="h-3.5 w-3.5" /> Marquer payées
                        </Button>
                        <Button size="sm" variant="ghost" onClick={() => transition('cancel')} disabled={busy}>
                            <XCircle className="h-3.5 w-3.5" /> Annuler
                        </Button>
                    </div>
                </div>
            ) : null}
            <Card bodyClassName="p-0">
                {!data ? <Spinner /> : <CommissionLines lines={data.data} showAgent selectable={data.can_manage} selected={selected} onToggle={(id) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]))} />}
            </Card>
        </div>
    );
}
