import { useEffect, useRef, useState } from 'react';
import api, { errorMessage } from '../../lib/api';
import { failedSelection } from '../../lib/delivery';
import { useMeta } from '../../context/MetaContext';
import useDeliveryModes from '../../hooks/useDeliveryModes';
import { Button } from '../ui';
import DeliveryModeMenu from './DeliveryModeMenu';
import AssignMenu from './AssignMenu';
import StatusMenu from './StatusMenu';
import PrintMenu from './PrintMenu';
import SendConfirmDialog from './SendConfirmDialog';
import SendResultDrawer from './SendResultDrawer';
import CarrierSendDialog from '../ozon/CarrierSendDialog';
import StatusMoveDialog from '../orders/StatusMoveDialog';
import { CancelOrderDialog, DeleteDraftDialog, LifecycleResults } from '../orders/OrderLifecycleDialogs';
import { apiPath } from './runAction';

/**
 * Compact selection bar: Envoyer avec › · Affecter à › · Changer le statut · Imprimer · Désélectionner.
 * « Annuler / Supprimer » appears when GET /api/orders/actions allows it for the selection.
 */
export default function BulkActionBar({ count, ids, onClear, onKeepFailed, onChanged }) {
    const meta = useMeta();
    const access = useDeliveryModes();
    const [menu, setMenu] = useState(null);
    const [carrier, setCarrier] = useState(null);
    const [preview, setPreview] = useState(null);
    const [panel, setPanel] = useState(null);
    const [move, setMove] = useState(null);
    const [notice, setNotice] = useState(null);
    const [lifecycle, setLifecycle] = useState({ can_cancel: false, can_delete_draft: false, orders: [] });
    const [cancelOpen, setCancelOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [results, setResults] = useState(null);
    const bar = useRef(null);

    useEffect(() => {
        if (!ids.length) return undefined;
        let ignore = false;
        api.get('/orders/actions', { params: { ids } })
            .then(({ data }) => { if (!ignore) setLifecycle(data); })
            .catch(() => { if (!ignore) setLifecycle({ can_cancel: false, can_delete_draft: false, orders: [] }); });
        return () => { ignore = true; };
    }, [ids]);

    useEffect(() => {
        if (!menu) return undefined;
        const onDown = (e) => {
            if (bar.current && !bar.current.contains(e.target)) setMenu(null);
        };
        document.addEventListener('mousedown', onDown);
        return () => document.removeEventListener('mousedown', onDown);
    }, [menu]);

    function finishSend(results) {
        const rows = results || [];
        setCarrier(null);
        setPreview(null);
        setPanel({ title: 'Envoi', results: rows });
        onKeepFailed?.(failedSelection(ids, rows));
        onChanged?.();
    }

    function pickMode(mode) {
        setMenu(null);
        if (mode.preview) setPreview(mode);
        else setCarrier(mode);
    }

    async function documentAction(mode, doc, format) {
        setMenu(null);
        try {
            const { data } = await api.post(apiPath(doc.url), { order_ids: ids, ...(format ? { format } : {}) });
            const notes = data.delivery_notes || (data.delivery_note ? [data.delivery_note] : []);
            setPanel({ title: `${doc.label} · ${mode.label}`, message: data.message, labels: data.labels, notes });
            onChanged?.();
        } catch (e) {
            setPanel({ title: `${doc.label} · ${mode.label}`, error: errorMessage(e) });
        }
    }

    async function assignDriver(driver) {
        setMenu(null);
        try {
            const { data } = await api.post('/local-delivery/assign', { order_ids: ids, driver_id: driver.id });
            setNotice({ ok: true, text: data.message });
            onChanged?.();
        } catch (e) {
            setNotice({ ok: false, text: e?.response?.data?.message || errorMessage(e) });
        }
    }

    async function assignAgent(userId) {
        setMenu(null);
        try {
            const { data } = await api.post('/orders/assign-agent', { order_ids: ids, user_id: userId });
            setNotice({ ok: true, text: data.message });
            onChanged?.();
        } catch (e) {
            setNotice({ ok: false, text: errorMessage(e) });
        }
    }

    async function printLabels() {
        setMenu(null);
        try {
            const { data } = await api.post('/carriers/labels', { order_ids: ids });
            if (data.labels?.length === 1) window.open(data.labels[0].pdf_url, '_blank', 'noopener');
            setPanel({ title: 'Étiquettes', message: data.message, labels: data.labels });
        } catch (e) {
            setPanel({ title: 'Étiquettes', error: errorMessage(e), labels: [] });
        }
    }

    const btn = 'h-8 whitespace-nowrap rounded-xl px-2.5 text-xs';

    return (
        <>
            <div ref={bar} className="sticky top-16 z-20 flex flex-nowrap items-center gap-2 overflow-x-auto rounded-2xl border border-blue-200 bg-blue-50 px-3 py-2 text-sm shadow-sm">
                <span className="shrink-0 font-semibold text-blue-800">{count} commande(s) sélectionnée(s)</span>
                <span className="mx-1 hidden h-4 w-px shrink-0 bg-blue-200 sm:block" />
                {access.can_ship ? (
                    <div className="relative shrink-0">
                        <Button size="sm" className={btn} onClick={() => setMenu(menu === 'send' ? null : 'send')} aria-expanded={menu === 'send'}>
                            Envoyer avec ›
                        </Button>
                        {menu === 'send' ? (
                            <div className="absolute left-0 top-full z-30 mt-1">
                                <DeliveryModeMenu
                                    orderCount={ids.length}
                                    onSelect={pickMode}
                                    onDocument={documentAction}
                                    onClose={() => setMenu(null)}
                                />
                            </div>
                        ) : null}
                    </div>
                ) : null}
                {access.can_assign_driver || access.can_assign_agent ? (
                    <div className="relative shrink-0">
                        <Button size="sm" variant="secondary" className={btn} onClick={() => setMenu(menu === 'assign' ? null : 'assign')} aria-expanded={menu === 'assign'}>
                            Affecter à ›
                        </Button>
                        {menu === 'assign' ? (
                            <div className="absolute left-0 top-full z-30 mt-1">
                                <AssignMenu onDriver={assignDriver} onAgent={assignAgent} onClose={() => setMenu(null)} />
                            </div>
                        ) : null}
                    </div>
                ) : null}
                <div className="relative shrink-0">
                    <Button size="sm" variant="secondary" className={btn} onClick={() => setMenu(menu === 'status' ? null : 'status')} aria-expanded={menu === 'status'}>
                        Changer le statut
                    </Button>
                    {menu === 'status' ? (
                        <div className="absolute left-0 top-full z-30 mt-1">
                            <StatusMenu
                                statuses={meta.statuses || []}
                                onClose={() => setMenu(null)}
                                onPick={(st) => {
                                    setMenu(null);
                                    setMove({
                                        orderIds: ids,
                                        status: st,
                                        label: `${count} commande(s) → « ${st.name} ». Mêmes règles qu’un changement manuel (transitions, champs obligatoires).`,
                                    });
                                }}
                            />
                        </div>
                    ) : null}
                </div>
                <div className="relative shrink-0">
                    <Button size="sm" variant="secondary" className={btn} onClick={() => setMenu(menu === 'print' ? null : 'print')} aria-expanded={menu === 'print'}>
                        Imprimer
                    </Button>
                    {menu === 'print' ? (
                        <div className="absolute left-0 top-full z-30 mt-1">
                            <PrintMenu onClose={() => setMenu(null)} onLabels={printLabels} onDocument={documentAction} />
                        </div>
                    ) : null}
                </div>
                {lifecycle.can_cancel || lifecycle.can_delete_draft ? (
                    <div className="relative shrink-0">
                        <Button size="sm" variant="secondary" className={btn} onClick={() => setMenu(menu === 'lifecycle' ? null : 'lifecycle')} aria-expanded={menu === 'lifecycle'}>
                            Annuler / Supprimer
                        </Button>
                        {menu === 'lifecycle' ? (
                            <div className="absolute left-0 top-full z-30 mt-1 w-56 rounded-xl border border-slate-200 bg-white py-1 text-sm shadow-lg">
                                {lifecycle.can_cancel ? (
                                    <button type="button" className="block w-full px-3 py-2 text-left hover:bg-slate-50" onClick={() => { setMenu(null); setCancelOpen(true); }}>Annuler la commande</button>
                                ) : null}
                                {lifecycle.can_delete_draft ? (
                                    <button type="button" className="block w-full px-3 py-2 text-left text-rose-700 hover:bg-rose-50" onClick={() => { setMenu(null); setDeleteOpen(true); }}>Supprimer le brouillon</button>
                                ) : null}
                            </div>
                        ) : null}
                    </div>
                ) : null}
                <Button size="sm" variant="ghost" className={`${btn} ml-auto`} onClick={onClear}>
                    Désélectionner
                </Button>
            </div>
            {notice ? (
                <div className={`rounded-xl px-3 py-2 text-sm font-medium ${notice.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-rose-50 text-rose-700'}`}>{notice.text}</div>
            ) : null}
            {carrier ? <SendConfirmDialog carrier={carrier} orderIds={ids} onClose={() => setCarrier(null)} onDone={finishSend} /> : null}
            {preview ? (
                <CarrierSendDialog
                    carrier={preview}
                    orderIds={ids}
                    onClose={() => setPreview(null)}
                    onDone={(data) => finishSend(data?.results || [])}
                />
            ) : null}
            <SendResultDrawer panel={panel} onClose={() => setPanel(null)} />
            {cancelOpen ? (
                <CancelOrderDialog
                    orders={(lifecycle.orders || []).filter((row) => row.can_cancel)}
                    onClose={() => setCancelOpen(false)}
                    onDone={({ results: rows, single }) => {
                        setCancelOpen(false);
                        if (rows) setResults(rows);
                        onChanged?.();
                        if (single) onChanged?.();
                    }}
                />
            ) : null}
            {deleteOpen ? (
                <DeleteDraftDialog
                    orders={(lifecycle.orders || []).filter((row) => row.can_delete_draft)}
                    onClose={() => setDeleteOpen(false)}
                    onDone={({ results: rows }) => {
                        setDeleteOpen(false);
                        if (rows) setResults(rows);
                        onChanged?.();
                    }}
                />
            ) : null}
            <LifecycleResults title="Annuler / Supprimer" results={results} onClose={() => setResults(null)} />
            <StatusMoveDialog
                move={move}
                onClose={() => setMove(null)}
                onDone={() => {
                    setMove(null);
                    onClear?.();
                    onChanged?.();
                }}
            />
        </>
    );
}
