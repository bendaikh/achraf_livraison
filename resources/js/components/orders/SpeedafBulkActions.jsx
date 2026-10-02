import { useState } from "react";
import {
    CheckCircle2,
    ExternalLink,
    Printer,
    Send,
    XCircle,
} from "lucide-react";
import api, { errorMessage } from "../../lib/api";
import { Alert, Button, Drawer } from "../ui";

/**
 * Commandes multi-select → Speedaf: send the selected orders (POST /api/speedaf/orders/send)
 * and get their waybill labels (POST /api/speedaf/labels). Results are shown in a drawer.
 */
export default function SpeedafBulkActions({ ids, onDone }) {
    const [busy, setBusy] = useState("");
    const [panel, setPanel] = useState(null); // {type:'send'|'labels', message, results|labels, error}

    async function send() {
        if (!window.confirm(`Envoyer ${ids.length} commande(s) à Speedaf ?`))
            return;
        setBusy("send");
        try {
            const { data } = await api.post("/speedaf/orders/send", {
                order_ids: ids,
            });
            setPanel({
                type: "send",
                message: data.message,
                results: data.results,
            });
        } catch (e) {
            setPanel({
                type: "send",
                error: errorMessage(e),
                results: e?.response?.data?.results || [],
            });
        } finally {
            setBusy("");
            onDone?.();
        }
    }

    async function labels() {
        setBusy("labels");
        try {
            const { data } = await api.post("/speedaf/labels", {
                order_ids: ids,
            });
            if (data.labels?.length === 1) {
                window.open(data.labels[0].pdf_url, "_blank", "noopener");
            }
            setPanel({
                type: "labels",
                message: data.message,
                labels: data.labels,
            });
        } catch (e) {
            setPanel({ type: "labels", error: errorMessage(e), labels: [] });
        } finally {
            setBusy("");
        }
    }

    return (
        <>
            <Button size="sm" onClick={send} disabled={!!busy}>
                <Send className="h-3.5 w-3.5" />{" "}
                {busy === "send" ? "Envoi…" : "Envoyer à Speedaf"}
            </Button>
            <Button
                size="sm"
                variant="secondary"
                onClick={labels}
                disabled={!!busy}
            >
                <Printer className="h-3.5 w-3.5" />{" "}
                {busy === "labels" ? "Préparation…" : "Étiquettes Speedaf"}
            </Button>

            <Drawer
                open={!!panel}
                onClose={() => setPanel(null)}
                title={
                    panel?.type === "labels"
                        ? "Étiquettes Speedaf"
                        : "Envoi à Speedaf"
                }
            >
                {panel ? (
                    <div className="space-y-3">
                        <Alert>{panel.error}</Alert>
                        {panel.message ? (
                            <Alert type="success">{panel.message}</Alert>
                        ) : null}
                        {panel.type === "send" && panel.results?.length ? (
                            <ul className="divide-y divide-slate-100 rounded-xl border border-slate-100">
                                {panel.results.map((r) => (
                                    <li
                                        key={r.order_id}
                                        className="flex items-start gap-2 px-3 py-2 text-sm"
                                    >
                                        {r.success ? (
                                            <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" />
                                        ) : (
                                            <XCircle className="mt-0.5 h-4 w-4 shrink-0 text-rose-600" />
                                        )}
                                        <div className="min-w-0">
                                            <div className="font-semibold text-slate-800">
                                                {r.reference}
                                            </div>
                                            <div
                                                className={`text-xs ${r.success ? "text-slate-500" : "text-rose-700"}`}
                                            >
                                                {r.message}
                                            </div>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        ) : null}
                        {panel.type === "labels" && panel.labels?.length ? (
                            <>
                                <ul className="divide-y divide-slate-100 rounded-xl border border-slate-100">
                                    {panel.labels.map((l) => (
                                        <li
                                            key={l.order_id}
                                            className="flex items-center justify-between gap-2 px-3 py-2 text-sm"
                                        >
                                            <div>
                                                <div className="font-semibold text-slate-800">
                                                    {l.reference}
                                                </div>
                                                <div className="font-mono text-xs text-slate-500">
                                                    {l.bill_code}
                                                </div>
                                            </div>
                                            <a
                                                href={l.pdf_url}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="inline-flex items-center gap-1 text-xs font-semibold text-blue-600 hover:text-blue-700"
                                            >
                                                <ExternalLink className="h-3.5 w-3.5" />{" "}
                                                Ouvrir le PDF
                                            </a>
                                        </li>
                                    ))}
                                </ul>
                                <p className="text-xs text-slate-400">
                                    Speedaf fournit un PDF par colis : ouvrez
                                    chaque étiquette pour l’imprimer.
                                </p>
                            </>
                        ) : null}
                    </div>
                ) : null}
            </Drawer>
        </>
    );
}
