import { useEffect, useState } from 'react';
import {
    CheckCircle2,
    Clock3,
    MessageCircle,
    Phone,
    PhoneOff,
    X,
    XCircle,
} from 'lucide-react';
import {
    formatDate,
    formatHistoryLine,
    formatMoney,
    orderDisplayName,
    statusBadgeStyle,
    telUrl,
} from './confirmationHelpers';

function ModalShell({ title, children, onClose }) {
    return (
        <div className="fixed inset-0 z-[70] flex items-end justify-center bg-slate-900/50 p-0 sm:items-center sm:p-4">
            <button type="button" className="absolute inset-0 cursor-default" aria-label="Fermer" onClick={onClose} />
            <div className="relative z-10 w-full max-w-md rounded-t-2xl bg-white p-5 shadow-xl sm:rounded-2xl">
                <div className="mb-4 flex items-center justify-between gap-3">
                    <h3 className="text-base font-bold text-slate-900">{title}</h3>
                    <button
                        type="button"
                        onClick={onClose}
                        className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                    >
                        <X className="h-4 w-4" />
                    </button>
                </div>
                {children}
            </div>
        </div>
    );
}

export default function ConfirmationDrawer({
    order,
    loading,
    busy,
    error,
    onClose,
    onConfirm,
    onNoAnswer,
    onPostpone,
    onCancel,
    onSaveNote,
}) {
    const [internalNote, setInternalNote] = useState(order?.internal_note || '');
    const [postponeOpen, setPostponeOpen] = useState(false);
    const [cancelOpen, setCancelOpen] = useState(false);
    const [recallDate, setRecallDate] = useState('');
    const [recallTime, setRecallTime] = useState('');
    const [postponeNote, setPostponeNote] = useState('');
    const [cancelReason, setCancelReason] = useState('');
    const [cancelComment, setCancelComment] = useState('');
    const [actionError, setActionError] = useState('');
    const [waOpening, setWaOpening] = useState(false);

    useEffect(() => {
        setInternalNote(order?.internal_note || '');
        setPostponeOpen(false);
        setCancelOpen(false);
        setRecallDate('');
        setRecallTime('');
        setPostponeNote('');
        setCancelReason('');
        setCancelComment('');
        setActionError('');
        setWaOpening(false);
    }, [order?.id, order?.internal_note]);

    if (!order && !loading) return null;

    const callHref = telUrl(order?.phone);

    const openInternalWhatsApp = async () => {
        if (!order?.id || !order?.phone) return;
        setWaOpening(true);
        try {
            const { data } = await window.axios.get(`/api/whatsapp/orders/${order.id}/conversation`);
            if (data?.path) {
                window.location.href = data.path;
                return;
            }
            window.location.href = `/whatsapp?search=${encodeURIComponent(order.phone)}`;
        } catch {
            window.location.href = `/whatsapp?search=${encodeURIComponent(order.phone)}`;
        } finally {
            setWaOpening(false);
        }
    };

    const canAct = Boolean(order?.can_act);

    const submitPostpone = async () => {
        setActionError('');
        if (!recallDate || !recallTime) {
            setActionError('Indiquez la date et l’heure du rappel.');
            return;
        }
        const recallAt = new Date(`${recallDate}T${recallTime}`);
        if (Number.isNaN(recallAt.getTime()) || recallAt <= new Date()) {
            setActionError('Le rappel doit être dans le futur.');
            return;
        }
        await onPostpone({
            recall_at: recallAt.toISOString(),
            note: postponeNote.trim() || undefined,
        });
        setPostponeOpen(false);
    };

    const submitCancel = async () => {
        setActionError('');
        if (cancelReason.trim().length < 3) {
            setActionError('Le motif d’annulation est obligatoire.');
            return;
        }
        await onCancel({
            reason: cancelReason.trim(),
            comment: cancelComment.trim() || undefined,
        });
        setCancelOpen(false);
    };

    return (
        <div className="fixed inset-0 z-[60] flex justify-end bg-slate-900/40">
            <button type="button" className="absolute inset-0 cursor-default" aria-label="Fermer" onClick={onClose} />
            <aside className="relative z-10 flex h-full w-full max-w-lg flex-col bg-white shadow-2xl">
                <div className="flex items-start justify-between gap-3 border-b border-slate-100 px-4 py-4 sm:px-5">
                    <div className="min-w-0">
                        <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">
                            Fiche de confirmation
                        </p>
                        {loading && !order ? (
                            <h2 className="mt-1 text-lg font-bold text-slate-900">Chargement…</h2>
                        ) : (
                            <>
                                <h2 className="mt-1 truncate text-lg font-bold text-slate-900">
                                    {orderDisplayName(order)}
                                </h2>
                                {order ? (
                                    <span
                                        className="mt-2 inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                        style={statusBadgeStyle(order.confirmation_status_color)}
                                    >
                                        {order.confirmation_status_label}
                                    </span>
                                ) : null}
                            </>
                        )}
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-slate-200 text-slate-500 hover:bg-slate-50"
                    >
                        <X className="h-4 w-4" />
                    </button>
                </div>

                <div className="flex-1 space-y-5 overflow-y-auto px-4 py-4 sm:px-5">
                    {error ? (
                        <div className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700">
                            {error}
                        </div>
                    ) : null}

                    {loading && !order ? (
                        <p className="text-sm font-medium text-slate-500">Chargement de la commande…</p>
                    ) : null}

                    {order ? (
                        <>
                            <section>
                                <h3 className="text-xs font-bold uppercase tracking-wide text-slate-400">Client</h3>
                                <div className="mt-2 space-y-1.5 rounded-2xl bg-slate-50 px-4 py-3 text-sm">
                                    <p className="font-bold text-slate-900">{order.customer_name || '—'}</p>
                                    <p className="font-medium text-slate-700">{order.phone || '—'}</p>
                                    <p className="font-medium text-slate-600">{order.address || '—'}</p>
                                    <p className="font-medium text-slate-600">{order.city || '—'}</p>
                                </div>
                                <div className="mt-3 grid grid-cols-2 gap-2">
                                    {callHref ? (
                                        <a
                                            href={callHref}
                                            className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-blue-600 text-sm font-bold text-white shadow-sm shadow-blue-500/20"
                                        >
                                            <Phone className="h-4 w-4" />
                                            Appeler
                                        </a>
                                    ) : (
                                        <button
                                            type="button"
                                            disabled
                                            className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-slate-100 text-sm font-bold text-slate-400"
                                        >
                                            <Phone className="h-4 w-4" />
                                            Appeler
                                        </button>
                                    )}
                                    {order?.phone ? (
                                        <button
                                            type="button"
                                            onClick={openInternalWhatsApp}
                                            disabled={waOpening}
                                            className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-emerald-600 text-sm font-bold text-white shadow-sm shadow-emerald-500/20 disabled:opacity-60"
                                        >
                                            <MessageCircle className="h-4 w-4" />
                                            {waOpening ? 'Ouverture…' : 'WhatsApp'}
                                        </button>
                                    ) : (
                                        <button
                                            type="button"
                                            disabled
                                            className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-slate-100 text-sm font-bold text-slate-400"
                                        >
                                            <MessageCircle className="h-4 w-4" />
                                            WhatsApp
                                        </button>
                                    )}
                                </div>
                            </section>

                            <section>
                                <h3 className="text-xs font-bold uppercase tracking-wide text-slate-400">Commande</h3>
                                <div className="mt-2 space-y-2">
                                    {(order.line_items || []).length === 0 ? (
                                        <p className="rounded-2xl bg-slate-50 px-4 py-3 text-sm font-medium text-slate-500">
                                            Aucun produit
                                        </p>
                                    ) : (
                                        (order.line_items || []).map((item, index) => (
                                            <div
                                                key={item.id || `${item.title}-${index}`}
                                                className="rounded-2xl border border-slate-100 bg-white px-4 py-3"
                                            >
                                                <div className="flex items-start justify-between gap-3">
                                                    <div className="min-w-0">
                                                        <p className="text-sm font-bold text-slate-900">
                                                            {item.title || 'Produit'}
                                                        </p>
                                                        {item.variant_title ? (
                                                            <p className="mt-0.5 text-xs font-medium text-slate-500">
                                                                {item.variant_title}
                                                            </p>
                                                        ) : null}
                                                        <p className="mt-1 text-xs font-semibold text-slate-500">
                                                            Qté {item.quantity ?? 0}
                                                        </p>
                                                    </div>
                                                    <p className="shrink-0 text-sm font-bold text-slate-800">
                                                        {formatMoney(item.price, order.currency)}
                                                    </p>
                                                </div>
                                            </div>
                                        ))
                                    )}
                                </div>
                                <div className="mt-3 space-y-1.5 rounded-2xl bg-slate-50 px-4 py-3 text-sm">
                                    <div className="flex justify-between gap-3 font-medium text-slate-600">
                                        <span>Livraison</span>
                                        <span>{formatMoney(order.shipping_price ?? 0, order.currency)}</span>
                                    </div>
                                    <div className="flex justify-between gap-3 text-base font-bold text-slate-900">
                                        <span>Total</span>
                                        <span>{formatMoney(order.total_price, order.currency)}</span>
                                    </div>
                                    <p className="pt-1 text-xs font-medium text-slate-400">
                                        Reçue le {formatDate(order.shopify_created_at)}
                                    </p>
                                </div>
                            </section>

                            <section>
                                <h3 className="text-xs font-bold uppercase tracking-wide text-slate-400">Notes</h3>
                                <div className="mt-2 space-y-3">
                                    <div className="rounded-2xl bg-slate-50 px-4 py-3">
                                        <p className="text-[11px] font-bold uppercase tracking-wide text-slate-400">
                                            Notes Shopify
                                        </p>
                                        <p className="mt-1 whitespace-pre-wrap text-sm font-medium text-slate-700">
                                            {order.note || 'Aucune note Shopify'}
                                        </p>
                                    </div>
                                    <div>
                                        <label className="text-[11px] font-bold uppercase tracking-wide text-slate-400">
                                            Note interne
                                        </label>
                                        <p className="mt-0.5 text-[11px] font-medium text-slate-400">
                                            Privée Lavfast Flow — ne modifie pas Shopify
                                        </p>
                                        <textarea
                                            value={internalNote}
                                            onChange={(e) => setInternalNote(e.target.value)}
                                            rows={3}
                                            className="mt-1.5 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none transition focus:border-blue-300 focus:ring-4 focus:ring-blue-500/10"
                                            placeholder="Ajouter une note interne…"
                                        />
                                        <button
                                            type="button"
                                            disabled={busy || internalNote === (order.internal_note || '')}
                                            onClick={() => onSaveNote(internalNote)}
                                            className="mt-2 rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-bold text-white disabled:opacity-40"
                                        >
                                            Enregistrer la note
                                        </button>
                                    </div>
                                </div>
                            </section>

                            <section>
                                <h3 className="text-xs font-bold uppercase tracking-wide text-slate-400">Historique</h3>
                                <ul className="mt-2 space-y-2">
                                    {(order.confirmation_history || []).length === 0 ? (
                                        <li className="rounded-xl bg-slate-50 px-3 py-2 text-sm text-slate-500">
                                            Aucun événement
                                        </li>
                                    ) : (
                                        [...(order.confirmation_history || [])].reverse().map((entry, index) => (
                                            <li
                                                key={`${entry.at}-${index}`}
                                                className="rounded-xl border border-slate-100 bg-white px-3 py-2"
                                            >
                                                <p className="text-sm font-medium text-slate-800">
                                                    {formatHistoryLine(entry)}
                                                </p>
                                            </li>
                                        ))
                                    )}
                                </ul>
                            </section>
                        </>
                    ) : null}
                </div>

                {order && canAct ? (
                    <div className="border-t border-slate-100 bg-white px-4 py-3 sm:px-5">
                        <div className="grid grid-cols-2 gap-2">
                            <button
                                type="button"
                                disabled={busy}
                                onClick={onConfirm}
                                className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-emerald-600 text-sm font-bold text-white shadow-sm disabled:opacity-50"
                            >
                                <CheckCircle2 className="h-4 w-4" />
                                Confirmer
                            </button>
                            <button
                                type="button"
                                disabled={busy}
                                onClick={onNoAnswer}
                                className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-orange-500 text-sm font-bold text-white shadow-sm disabled:opacity-50"
                            >
                                <PhoneOff className="h-4 w-4" />
                                Pas de réponse
                            </button>
                            <button
                                type="button"
                                disabled={busy}
                                onClick={() => setPostponeOpen(true)}
                                className="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-violet-200 bg-violet-50 text-sm font-bold text-violet-700 disabled:opacity-50"
                            >
                                <Clock3 className="h-4 w-4" />
                                Reporter
                            </button>
                            <button
                                type="button"
                                disabled={busy}
                                onClick={() => setCancelOpen(true)}
                                className="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-rose-200 bg-rose-50 text-sm font-bold text-rose-700 disabled:opacity-50"
                            >
                                <XCircle className="h-4 w-4" />
                                Annuler
                            </button>
                        </div>
                    </div>
                ) : null}
            </aside>

            {postponeOpen ? (
                <ModalShell title="Reporter la commande" onClose={() => setPostponeOpen(false)}>
                    <div className="space-y-3">
                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <label className="text-xs font-semibold text-slate-500">Date du rappel *</label>
                                <input
                                    type="date"
                                    value={recallDate}
                                    onChange={(e) => setRecallDate(e.target.value)}
                                    className="mt-1 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none focus:border-blue-300 focus:ring-4 focus:ring-blue-500/10"
                                />
                            </div>
                            <div>
                                <label className="text-xs font-semibold text-slate-500">Heure du rappel *</label>
                                <input
                                    type="time"
                                    value={recallTime}
                                    onChange={(e) => setRecallTime(e.target.value)}
                                    className="mt-1 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none focus:border-blue-300 focus:ring-4 focus:ring-blue-500/10"
                                />
                            </div>
                        </div>
                        <div>
                            <label className="text-xs font-semibold text-slate-500">Note (facultatif)</label>
                            <textarea
                                value={postponeNote}
                                onChange={(e) => setPostponeNote(e.target.value)}
                                rows={2}
                                className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-blue-300 focus:ring-4 focus:ring-blue-500/10"
                                placeholder="Ex. client occupé, rappeler demain matin"
                            />
                        </div>
                        {actionError ? <p className="text-sm font-medium text-rose-600">{actionError}</p> : null}
                        <button
                            type="button"
                            disabled={busy}
                            onClick={submitPostpone}
                            className="inline-flex h-11 w-full items-center justify-center rounded-xl bg-violet-600 text-sm font-bold text-white disabled:opacity-50"
                        >
                            Valider le report
                        </button>
                    </div>
                </ModalShell>
            ) : null}

            {cancelOpen ? (
                <ModalShell title="Annuler la commande" onClose={() => setCancelOpen(false)}>
                    <div className="space-y-3">
                        <div>
                            <label className="text-xs font-semibold text-slate-500">Motif *</label>
                            <textarea
                                value={cancelReason}
                                onChange={(e) => setCancelReason(e.target.value)}
                                rows={2}
                                className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-blue-300 focus:ring-4 focus:ring-blue-500/10"
                                placeholder="Ex. client refuse, doublon, adresse invalide…"
                            />
                        </div>
                        <div>
                            <label className="text-xs font-semibold text-slate-500">Commentaire (facultatif)</label>
                            <textarea
                                value={cancelComment}
                                onChange={(e) => setCancelComment(e.target.value)}
                                rows={2}
                                className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-blue-300 focus:ring-4 focus:ring-blue-500/10"
                                placeholder="Détail complémentaire…"
                            />
                        </div>
                        {actionError ? <p className="text-sm font-medium text-rose-600">{actionError}</p> : null}
                        <button
                            type="button"
                            disabled={busy}
                            onClick={submitCancel}
                            className="inline-flex h-11 w-full items-center justify-center rounded-xl bg-rose-600 text-sm font-bold text-white disabled:opacity-50"
                        >
                            Confirmer l’annulation
                        </button>
                    </div>
                </ModalShell>
            ) : null}
        </div>
    );
}
