import { useEffect, useState } from 'react';
import { UserCheck } from 'lucide-react';
import api, { errorMessage } from '../../lib/api';
import { useMeta } from '../../context/MetaContext';
import { Alert, Button, Drawer } from '../ui';

/** Assigns selected orders to a confirmation/commercial agent (T6). History kind "agent". */
export default function AgentAssignDrawer({ open, onClose, orderIds = [], onDone }) {
    const meta = useMeta();
    const [selected, setSelected] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [result, setResult] = useState(null);

    useEffect(() => {
        if (open) {
            setSelected('');
            setError(null);
            setResult(null);
        }
    }, [open]);

    async function submit() {
        setBusy(true);
        setError(null);
        try {
            const { data } = await api.post('/orders/assign-agent', { order_ids: orderIds, user_id: selected === 'none' ? null : Number(selected) });
            setResult(data.message);
            onDone?.();
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy(false);
        }
    }

    const services = Object.fromEntries((meta.services || []).map((s) => [s.id, s.name]));
    const options = [...(meta.users || []).map((u) => ({ id: String(u.id), name: u.name, sub: services[u.service_id] })), { id: 'none', name: 'Retirer l’agent', sub: 'Commandes non assignées' }];

    return (
        <Drawer
            open={open}
            onClose={onClose}
            title="Assigner à un agent"
            footer={
                result ? (
                    <Button className="w-full" onClick={onClose}>
                        Fermer
                    </Button>
                ) : (
                    <Button className="w-full" onClick={submit} disabled={!selected || busy}>
                        <UserCheck className="h-4 w-4" /> {busy ? 'Assignation…' : `Assigner ${orderIds.length} commande(s)`}
                    </Button>
                )
            }
        >
            <div className="space-y-3">
                <p className="text-sm text-slate-500">L’agent retrouve ces commandes avec le filtre « Mes commandes » du Centre de confirmation. Chaque changement est historisé.</p>
                <Alert type="success">{result}</Alert>
                <Alert>{error}</Alert>
                {!result ? (
                    <div className="space-y-1.5">
                        {options.map((o) => (
                            <label key={o.id} className={`flex cursor-pointer items-center gap-3 rounded-xl border px-3 py-2.5 text-sm ${selected === o.id ? 'border-blue-400 bg-blue-50' : 'border-slate-200 hover:bg-slate-50'}`}>
                                <input type="radio" name="agent" value={o.id} checked={selected === o.id} onChange={() => setSelected(o.id)} />
                                <span className="font-semibold text-slate-800">{o.name}</span>
                                {o.sub ? <span className="text-xs text-slate-400">{o.sub}</span> : null}
                            </label>
                        ))}
                    </div>
                ) : null}
            </div>
        </Drawer>
    );
}
