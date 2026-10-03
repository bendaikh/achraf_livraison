import { useEffect, useMemo, useState } from 'react';
import { Bike, CheckCircle2, Phone, Search, Wallet, XCircle } from 'lucide-react';
import api, { errorMessage } from '../../lib/api';
import { formatDH } from '../../lib/format';
import { Alert, Button, Drawer, Input, Spinner } from '../ui';

/**
 * « Choisir un livreur » — assigns one or many orders to a local driver
 * (POST /api/local-delivery/assign). Used by the Commandes selection bar, the quick-ship
 * popup and the order page. Re-assignment is allowed and historised server-side.
 */
export default function LocalAssignDrawer({ open, onClose, orderIds = [], currentDriverId = null, onDone }) {
    const [drivers, setDrivers] = useState(null);
    const [error, setError] = useState(null);
    const [search, setSearch] = useState('');
    const [selected, setSelected] = useState(null);
    const [busy, setBusy] = useState(false);
    const [result, setResult] = useState(null);

    useEffect(() => {
        if (!open) return;
        setResult(null);
        setError(null);
        setSelected(null);
        setSearch('');
        api.get('/local-delivery/drivers')
            .then(({ data }) => setDrivers(data.drivers))
            .catch((e) => setError(errorMessage(e)));
    }, [open]);

    const list = useMemo(() => {
        const q = search.trim().toLowerCase();
        return (drivers || []).filter((d) => !q || `${d.name} ${d.phone || ''} ${d.city || ''}`.toLowerCase().includes(q));
    }, [drivers, search]);

    async function submit() {
        if (!selected) return;
        setBusy(true);
        setError(null);
        try {
            const { data } = await api.post('/local-delivery/assign', { order_ids: orderIds, driver_id: selected });
            setResult(data);
            onDone?.(data);
        } catch (e) {
            setError(errorMessage(e));
            if (e?.response?.data?.results) setResult(e.response.data);
        } finally {
            setBusy(false);
        }
    }

    const count = orderIds.length;

    return (
        <Drawer
            open={open}
            onClose={onClose}
            title="Choisir un livreur"
            footer={
                result ? (
                    <Button className="w-full" onClick={onClose}>
                        Fermer
                    </Button>
                ) : (
                    <Button className="w-full" onClick={submit} disabled={!selected || busy}>
                        <Bike className="h-4 w-4" />
                        {busy ? 'Affectation…' : `Affecter ${count > 1 ? `${count} commandes` : 'la commande'}`}
                    </Button>
                )
            }
        >
            <div className="space-y-3">
                <p className="text-sm text-slate-500">
                    {count > 1 ? `${count} commandes sélectionnées` : '1 commande'} → livraison locale. Le statut passe à « Attribuée » et les commandes apparaissent
                    immédiatement dans l’espace du livreur.
                </p>
                <Alert>{error}</Alert>
                {result ? (
                    <>
                        {result.assigned_count > 0 ? <Alert type="success">{result.message}</Alert> : null}
                        <ul className="divide-y divide-slate-100 rounded-xl border border-slate-100">
                            {(result.results || []).map((r) => (
                                <li key={r.order_id} className="flex items-start gap-2 px-3 py-2 text-sm">
                                    {r.success ? (
                                        <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" />
                                    ) : (
                                        <XCircle className="mt-0.5 h-4 w-4 shrink-0 text-rose-600" />
                                    )}
                                    <div className="min-w-0">
                                        <div className="font-semibold text-slate-800">{r.reference}</div>
                                        <div className={`text-xs ${r.success ? 'text-slate-500' : 'text-rose-700'}`}>{r.message}</div>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </>
                ) : (
                    <>
                        <div className="relative">
                            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Nom, téléphone, ville…" className="pl-9" />
                        </div>
                        {!drivers ? (
                            <Spinner />
                        ) : list.length === 0 ? (
                            <p className="rounded-xl bg-slate-50 px-3 py-6 text-center text-sm text-slate-500">Aucun livreur actif.</p>
                        ) : (
                            <ul className="space-y-2" role="radiogroup" aria-label="Livreurs actifs">
                                {list.map((d) => {
                                    const active = selected === d.id;
                                    return (
                                        <li key={d.id}>
                                            <button
                                                type="button"
                                                role="radio"
                                                aria-checked={active}
                                                onClick={() => setSelected(d.id)}
                                                className={`flex w-full items-center gap-3 rounded-xl border px-3 py-2.5 text-left transition ${active ? 'border-blue-400 bg-blue-50 ring-2 ring-blue-100' : 'border-slate-200 hover:bg-slate-50'}`}
                                            >
                                                <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full ${active ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-500'}`}>
                                                    <Bike className="h-4 w-4" />
                                                </span>
                                                <span className="min-w-0 flex-1">
                                                    <span className="flex items-center gap-2">
                                                        <span className="truncate font-semibold text-slate-800">{d.name}</span>
                                                        <span className="rounded-full bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700">Actif</span>
                                                        {currentDriverId === d.id ? (
                                                            <span className="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-600">Actuel</span>
                                                        ) : null}
                                                    </span>
                                                    <span className="mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-slate-500">
                                                        {d.phone ? (
                                                            <span className="inline-flex items-center gap-1">
                                                                <Phone className="h-3 w-3" /> {d.phone}
                                                            </span>
                                                        ) : null}
                                                        <span>{d.missions_in_progress} mission(s) en cours</span>
                                                        <span className="inline-flex items-center gap-1">
                                                            <Wallet className="h-3 w-3" /> Caisse non clôturée : {formatDH(d.cod_held)}
                                                        </span>
                                                    </span>
                                                </span>
                                            </button>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </>
                )}
            </div>
        </Drawer>
    );
}
