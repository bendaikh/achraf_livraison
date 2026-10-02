import { useState } from "react";
import { Ban, Printer, RefreshCw, Send, Truck } from "lucide-react";
import { Link } from "react-router-dom";
import api, { errorMessage } from "../../lib/api";
import { formatDateTime } from "../../lib/format";
import { Alert, Button, Card } from "../ui";

/** Fiche commande → Speedaf: send / cancel / refresh tracking / print the waybill. */
export default function SpeedafOrderCard({ order, onChanged }) {
    const [busy, setBusy] = useState("");
    const [msg, setMsg] = useState(null);
    const [error, setError] = useState(null);
    const shipment = order.speedaf;
    const tracks =
        (order.speedaf_history || []).find((s) => s.id === shipment?.id)
            ?.tracks || [];

    async function act(name, fn) {
        setBusy(name);
        setMsg(null);
        setError(null);
        try {
            const { data } = await fn();
            if (data.data) onChanged(data.data);
            setMsg(data.message);
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy("");
        }
    }

    const send = () =>
        act("send", () =>
            api.post("/speedaf/orders/send", { order_ids: [order.id] }),
        );
    const sync = () =>
        act("sync", () => api.post(`/speedaf/orders/${order.id}/sync`));
    const cancel = () => {
        const reason = window.prompt(
            "Motif de l’annulation Speedaf :",
            "Annulation expéditeur",
        );
        if (reason === null) return;
        act("cancel", () =>
            api.post(`/speedaf/orders/${order.id}/cancel`, { reason }),
        );
    };

    return (
        <Card
            title="Speedaf"
            actions={<Truck className="h-4 w-4 text-slate-400" />}
        >
            <div className="space-y-3">
                <Alert>{error}</Alert>
                <Alert type="success">{msg}</Alert>
                {shipment ? (
                    <>
                        <div className="rounded-xl bg-orange-50 px-3 py-2 ring-1 ring-orange-100">
                            <div className="text-[11px] font-semibold uppercase tracking-wide text-orange-500">
                                N° de suivi{" "}
                                {shipment.environment === "uat" ? "(test)" : ""}
                            </div>
                            <div className="font-mono text-base font-bold text-slate-900">
                                {shipment.bill_code}
                            </div>
                            <div className="mt-1 text-sm font-semibold text-orange-700">
                                {shipment.status_label || "Créée chez Speedaf"}
                            </div>
                            {shipment.status_message ? (
                                <div className="text-xs text-slate-600">
                                    {shipment.status_message}
                                </div>
                            ) : null}
                            <div className="mt-1 text-[11px] text-slate-400">
                                {shipment.status_at
                                    ? `Dernier événement : ${formatDateTime(shipment.status_at)}`
                                    : `Créée le ${formatDateTime(shipment.created_at)}`}
                            </div>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <a
                                href={`/api/speedaf/orders/${order.id}/label`}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex h-8 items-center gap-1.5 rounded-xl bg-blue-600 px-2.5 text-xs font-semibold text-white hover:bg-blue-700"
                            >
                                <Printer className="h-3.5 w-3.5" /> Imprimer
                                l’étiquette
                            </a>
                            <Button
                                size="sm"
                                variant="secondary"
                                onClick={sync}
                                disabled={!!busy}
                            >
                                <RefreshCw
                                    className={`h-3.5 w-3.5 ${busy === "sync" ? "animate-spin" : ""}`}
                                />{" "}
                                Actualiser le suivi
                            </Button>
                            {shipment.state === "created" ? (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={cancel}
                                    disabled={!!busy}
                                    className="text-rose-600"
                                >
                                    <Ban className="h-3.5 w-3.5" /> Annuler
                                    l’envoi
                                </Button>
                            ) : null}
                        </div>
                        {tracks.length ? (
                            <ol className="space-y-2 border-l-2 border-orange-100 pl-3">
                                {[...tracks].reverse().map((t, i) => (
                                    <li
                                        key={`${t.time}-${i}`}
                                        className="text-xs"
                                    >
                                        <div className="font-semibold text-slate-700">
                                            {t.actionName || t.action}
                                        </div>
                                        <div className="text-slate-500">
                                            {t.msgLoc || t.msgEng || t.message}
                                        </div>
                                        <div className="text-slate-400">
                                            {t.time}
                                        </div>
                                    </li>
                                ))}
                            </ol>
                        ) : null}
                    </>
                ) : (
                    <>
                        <p className="text-sm text-slate-500">
                            Cette commande n’a pas encore été envoyée à Speedaf.
                        </p>
                        <div className="flex flex-wrap items-center gap-2">
                            <Button size="sm" onClick={send} disabled={!!busy}>
                                <Send className="h-3.5 w-3.5" />{" "}
                                {busy === "send"
                                    ? "Envoi…"
                                    : "Envoyer à Speedaf"}
                            </Button>
                            <Link
                                to="/integrations/speedaf"
                                className="text-xs font-semibold text-blue-600 hover:text-blue-700"
                            >
                                Paramètres Speedaf
                            </Link>
                        </div>
                    </>
                )}
            </div>
        </Card>
    );
}
