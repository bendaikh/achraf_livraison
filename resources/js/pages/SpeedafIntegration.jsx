import { useCallback, useEffect, useState } from "react";
import {
    CheckCircle2,
    Copy,
    KeyRound,
    Link2,
    Plug,
    RefreshCw,
    Save,
    Truck,
    XCircle,
} from "lucide-react";
import api, { errorMessage, fieldErrors } from "../lib/api";
import { useMeta } from "../context/MetaContext";
import { formatDateTime } from "../lib/format";
import {
    Alert,
    Button,
    Card,
    Checkbox,
    Field,
    Input,
    PageHeader,
    Select,
    Spinner,
} from "../components/ui";

const EDITABLE = [
    "enabled",
    "environment",
    "app_code",
    "customer_code",
    "platform_source",
    "parcel_type",
    "delivery_type",
    "transport_type",
    "ship_type",
    "pay_method",
    "goods_type",
    "pickup_aging",
    "allow_open",
    "default_weight",
    "country_code",
    "currency",
    "label_type",
    "label_with_logo",
    "sender_name",
    "sender_mobile",
    "sender_address",
    "sender_province",
    "sender_city",
    "sender_district",
    "status_mapping",
    "auto_sync",
];

function Toggle({ checked, onChange, label, hint }) {
    return (
        <label className="flex cursor-pointer items-start gap-3">
            <button
                type="button"
                role="switch"
                aria-checked={checked}
                onClick={() => onChange(!checked)}
                className={`relative mt-0.5 inline-flex h-6 w-11 shrink-0 items-center rounded-full transition ${checked ? "bg-blue-600" : "bg-slate-300"}`}
            >
                <span
                    className={`inline-block h-5 w-5 transform rounded-full bg-white shadow transition ${checked ? "translate-x-5" : "translate-x-0.5"}`}
                />
            </button>
            <span>
                <span className="block text-sm font-semibold text-slate-800">
                    {label}
                </span>
                {hint ? (
                    <span className="block text-xs text-slate-500">{hint}</span>
                ) : null}
            </span>
        </label>
    );
}

function Options({ list }) {
    return (list || []).map((o) => (
        <option key={o.value} value={o.value}>
            {o.label}
        </option>
    ));
}

export default function SpeedafIntegration() {
    const meta = useMeta();
    const [data, setData] = useState(null);
    const [form, setForm] = useState(null);
    const [secret, setSecret] = useState("");
    const [errors, setErrors] = useState({});
    const [msg, setMsg] = useState(null);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState("");
    const [test, setTest] = useState(null);

    const apply = useCallback((d) => {
        setData(d);
        setForm(Object.fromEntries(EDITABLE.map((k) => [k, d[k]])));
        setTest(
            d.last_tested_at
                ? {
                      ok: d.last_test_ok,
                      message: d.last_test_message,
                      at: d.last_tested_at,
                  }
                : null,
        );
    }, []);

    useEffect(() => {
        api.get("/integrations/speedaf")
            .then(({ data: res }) => apply(res.data))
            .catch((e) =>
                setError(
                    errorMessage(
                        e,
                        "Impossible de charger l’intégration Speedaf.",
                    ),
                ),
            );
    }, [apply]);

    const set = (key, value) => setForm((f) => ({ ...f, [key]: value }));

    async function run(name, fn) {
        setBusy(name);
        setMsg(null);
        setError(null);
        try {
            await fn();
        } catch (e) {
            setErrors(fieldErrors(e));
            setError(errorMessage(e));
        } finally {
            setBusy("");
        }
    }

    const save = (e) => {
        e?.preventDefault();
        return run("save", async () => {
            setErrors({});
            const payload = {
                ...form,
                default_weight: Number(form.default_weight || 1),
                pickup_aging: Number(form.pickup_aging),
                label_type: Number(form.label_type),
            };
            if (secret.trim()) payload.secret_key = secret.trim();
            const { data: res } = await api.put(
                "/integrations/speedaf",
                payload,
            );
            apply(res.data);
            setSecret("");
            setMsg(res.message);
        });
    };

    const testConnection = () =>
        run("test", async () => {
            try {
                const { data: res } = await api.post(
                    "/integrations/speedaf/test",
                    {
                        environment: form.environment,
                        app_code: form.app_code || "",
                        country_code: form.country_code,
                    },
                );
                setTest({
                    ok: true,
                    message: res.message,
                    at: new Date().toISOString(),
                });
            } catch (e) {
                setTest({
                    ok: false,
                    message: errorMessage(e, "Échec du test de connexion."),
                    at: new Date().toISOString(),
                });
            }
        });

    const subscribe = () =>
        run("webhook", async () => {
            const { data: res } = await api.post(
                "/integrations/speedaf/webhook/subscribe",
            );
            apply(res.data);
            setMsg(res.message);
        });

    const syncNow = () =>
        run("sync", async () => {
            const { data: res } = await api.post("/integrations/speedaf/sync");
            apply(res.data);
            setMsg(res.message);
        });

    if (!form) return error ? <Alert>{error}</Alert> : <Spinner />;

    const o = data.options;
    const envInfo = o.environments.find((e) => e.value === form.environment);

    return (
        <form onSubmit={save} className="space-y-4">
            <PageHeader
                title="Speedaf"
                subtitle="Envoyez vos commandes chez Speedaf, imprimez les étiquettes et suivez les statuts automatiquement."
                actions={
                    <>
                        <span
                            className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset ${
                                data.enabled && data.ready
                                    ? "bg-emerald-50 text-emerald-700 ring-emerald-200"
                                    : "bg-slate-100 text-slate-600 ring-slate-200"
                            }`}
                        >
                            <span
                                className={`h-2 w-2 rounded-full ${data.enabled && data.ready ? "bg-emerald-500" : "bg-slate-400"}`}
                            />
                            {data.enabled
                                ? data.ready
                                    ? "Active"
                                    : "Incomplète"
                                : "Désactivée"}
                        </span>
                        <Button type="submit" disabled={!!busy}>
                            <Save className="h-4 w-4" />{" "}
                            {busy === "save"
                                ? "Enregistrement…"
                                : "Enregistrer"}
                        </Button>
                    </>
                }
            />

            <Alert type="success">{msg}</Alert>
            <Alert>{error}</Alert>
            {data.enabled && data.missing.length ? (
                <div className="rounded-xl bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800 ring-1 ring-amber-200">
                    À compléter avant d’envoyer des commandes :{" "}
                    {data.missing.join(", ")}.
                </div>
            ) : null}

            <div className="grid gap-4 lg:grid-cols-2">
                <Card
                    title="Connexion API"
                    subtitle="Identifiants fournis par Speedaf"
                    actions={<KeyRound className="h-4 w-4 text-slate-400" />}
                >
                    <div className="space-y-4">
                        <Toggle
                            checked={!!form.enabled}
                            onChange={(v) => set("enabled", v)}
                            label="Activer l’intégration Speedaf"
                            hint="Affiche les actions Speedaf dans Commandes et active la synchronisation des statuts."
                        />
                        <Field
                            label="Environnement"
                            hint={envInfo ? `API : ${envInfo.url}` : null}
                            error={errors.environment}
                        >
                            <Select
                                value={form.environment}
                                onChange={(e) =>
                                    set("environment", e.target.value)
                                }
                            >
                                {o.environments.map((e) => (
                                    <option key={e.value} value={e.value}>
                                        {e.label}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field
                                label="App Code"
                                error={errors.app_code}
                                hint={
                                    form.environment === "uat"
                                        ? `Test public Maroc : ${o.uat_sample_app_code}`
                                        : "Fourni par Speedaf pour la production"
                                }
                            >
                                <Input
                                    value={form.app_code || ""}
                                    onChange={(e) =>
                                        set("app_code", e.target.value)
                                    }
                                    placeholder="ex. MA000025"
                                    autoComplete="off"
                                />
                            </Field>
                            <Field
                                label="Code client (customerCode)"
                                error={errors.customer_code}
                                hint="Fourni par Speedaf"
                            >
                                <Input
                                    value={form.customer_code || ""}
                                    onChange={(e) =>
                                        set("customer_code", e.target.value)
                                    }
                                    placeholder="ex. MA000025"
                                    autoComplete="off"
                                />
                            </Field>
                            <Field
                                label="Platform source"
                                error={errors.platform_source}
                                hint="Valeur indiquée par Speedaf"
                            >
                                <Input
                                    value={form.platform_source || ""}
                                    onChange={(e) =>
                                        set("platform_source", e.target.value)
                                    }
                                />
                            </Field>
                            <Field
                                label="Clé secrète (webhook)"
                                error={errors.secret_key}
                                hint={
                                    data.has_secret_key
                                        ? `Enregistrée : ${data.secret_key_hint} — laisser vide pour conserver`
                                        : "Sert à vérifier la signature des notifications Speedaf"
                                }
                            >
                                <Input
                                    type="password"
                                    value={secret}
                                    onChange={(e) => setSecret(e.target.value)}
                                    placeholder={
                                        data.has_secret_key
                                            ? data.secret_key_hint
                                            : "secretKey"
                                    }
                                    autoComplete="new-password"
                                />
                            </Field>
                        </div>
                        <div className="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-4">
                            <Button
                                variant="secondary"
                                onClick={testConnection}
                                disabled={!!busy}
                            >
                                <Plug className="h-4 w-4" />{" "}
                                {busy === "test"
                                    ? "Test en cours…"
                                    : "Tester la connexion"}
                            </Button>
                            {test ? (
                                <div
                                    className={`flex min-w-0 flex-1 items-start gap-1.5 text-sm font-medium ${test.ok ? "text-emerald-700" : "text-rose-700"}`}
                                >
                                    {test.ok ? (
                                        <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" />
                                    ) : (
                                        <XCircle className="mt-0.5 h-4 w-4 shrink-0" />
                                    )}
                                    <span>
                                        {test.message}
                                        {test.at ? (
                                            <span className="ml-1 text-xs font-normal text-slate-400">
                                                ({formatDateTime(test.at)})
                                            </span>
                                        ) : null}
                                    </span>
                                </div>
                            ) : null}
                        </div>
                    </div>
                </Card>

                <Card
                    title="Expéditeur / ramassage"
                    subtitle="Adresse de ramassage envoyée avec chaque colis"
                    actions={<Truck className="h-4 w-4 text-slate-400" />}
                >
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field
                            label="Nom expéditeur *"
                            error={errors.sender_name}
                        >
                            <Input
                                value={form.sender_name || ""}
                                onChange={(e) =>
                                    set("sender_name", e.target.value)
                                }
                                placeholder="Lavfast Flow"
                            />
                        </Field>
                        <Field
                            label="Téléphone expéditeur *"
                            error={errors.sender_mobile}
                        >
                            <Input
                                value={form.sender_mobile || ""}
                                onChange={(e) =>
                                    set("sender_mobile", e.target.value)
                                }
                                placeholder="06…"
                            />
                        </Field>
                        <Field
                            label="Adresse de ramassage *"
                            error={errors.sender_address}
                            className="sm:col-span-2"
                        >
                            <Input
                                value={form.sender_address || ""}
                                onChange={(e) =>
                                    set("sender_address", e.target.value)
                                }
                                placeholder="N°, rue, quartier"
                            />
                        </Field>
                        <Field label="Ville *" error={errors.sender_city}>
                            <Input
                                value={form.sender_city || ""}
                                onChange={(e) =>
                                    set("sender_city", e.target.value)
                                }
                                placeholder="Casablanca"
                            />
                        </Field>
                        <Field
                            label="Quartier / district"
                            error={errors.sender_district}
                            hint="Par défaut : la ville"
                        >
                            <Input
                                value={form.sender_district || ""}
                                onChange={(e) =>
                                    set("sender_district", e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Région (province)"
                            error={errors.sender_province}
                            hint="Détectée automatiquement depuis la ville si vide"
                            className="sm:col-span-2"
                        >
                            <Input
                                value={form.sender_province || ""}
                                onChange={(e) =>
                                    set("sender_province", e.target.value)
                                }
                                placeholder="ex. Casablanca - Settat"
                            />
                        </Field>
                    </div>
                </Card>

                <Card
                    title="Options d’expédition"
                    subtitle="Valeurs par défaut de chaque colis (codes Speedaf)"
                >
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label="Pays">
                            <Select
                                value={form.country_code}
                                onChange={(e) =>
                                    set("country_code", e.target.value)
                                }
                            >
                                <Options list={o.countries} />
                            </Select>
                        </Field>
                        <Field label="Devise COD" error={errors.currency}>
                            <Input
                                value={form.currency || ""}
                                maxLength={3}
                                onChange={(e) =>
                                    set(
                                        "currency",
                                        e.target.value.toUpperCase(),
                                    )
                                }
                            />
                        </Field>
                        <Field label="Type de colis">
                            <Select
                                value={form.parcel_type}
                                onChange={(e) =>
                                    set("parcel_type", e.target.value)
                                }
                            >
                                <Options list={o.parcel_types} />
                            </Select>
                        </Field>
                        <Field label="Mode de livraison">
                            <Select
                                value={form.delivery_type}
                                onChange={(e) =>
                                    set("delivery_type", e.target.value)
                                }
                            >
                                <Options list={o.delivery_types} />
                            </Select>
                        </Field>
                        <Field label="Transport">
                            <Select
                                value={form.transport_type}
                                onChange={(e) =>
                                    set("transport_type", e.target.value)
                                }
                            >
                                <Options list={o.transport_types} />
                            </Select>
                        </Field>
                        <Field label="Paiement des frais Speedaf">
                            <Select
                                value={form.pay_method}
                                onChange={(e) =>
                                    set("pay_method", e.target.value)
                                }
                            >
                                <Options list={o.pay_methods} />
                            </Select>
                        </Field>
                        <Field label="Type de marchandise">
                            <Select
                                value={form.goods_type}
                                onChange={(e) =>
                                    set("goods_type", e.target.value)
                                }
                            >
                                <Options list={o.goods_types} />
                            </Select>
                        </Field>
                        <Field label="Ramassage">
                            <Select
                                value={String(form.pickup_aging)}
                                onChange={(e) =>
                                    set("pickup_aging", e.target.value)
                                }
                            >
                                <Options list={o.pickup_agings} />
                            </Select>
                        </Field>
                        <Field
                            label="Poids par défaut (kg)"
                            error={errors.default_weight}
                            hint="Si les articles n’ont pas de poids"
                        >
                            <Input
                                type="number"
                                min="0.001"
                                step="0.1"
                                value={form.default_weight ?? ""}
                                onChange={(e) =>
                                    set("default_weight", e.target.value)
                                }
                            />
                        </Field>
                        <Field label="Format d’étiquette">
                            <Select
                                value={String(form.label_type)}
                                onChange={(e) =>
                                    set("label_type", e.target.value)
                                }
                            >
                                <Options list={o.label_types} />
                            </Select>
                        </Field>
                        <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
                            <Checkbox
                                checked={!!form.allow_open}
                                onChange={(e) =>
                                    set("allow_open", e.target.checked)
                                }
                            />{" "}
                            Autoriser l’ouverture du colis
                        </label>
                        <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
                            <Checkbox
                                checked={!!form.label_with_logo}
                                onChange={(e) =>
                                    set("label_with_logo", e.target.checked)
                                }
                            />{" "}
                            Logo Speedaf sur l’étiquette
                        </label>
                    </div>
                </Card>

                <Card
                    title="Synchronisation des statuts"
                    subtitle="Webhook Speedaf + vérification planifiée toutes les 30 min"
                    actions={<RefreshCw className="h-4 w-4 text-slate-400" />}
                >
                    <div className="space-y-4">
                        <Toggle
                            checked={!!form.auto_sync}
                            onChange={(v) => set("auto_sync", v)}
                            label="Synchronisation automatique"
                            hint="Tâche planifiée « speedaf:sync » (cron Laravel schedule:run)."
                        />
                        <Field
                            label="URL du webhook à déclarer chez Speedaf"
                            hint={
                                data.webhook_subscribed_at
                                    ? `Enregistré le ${formatDateTime(data.webhook_subscribed_at)}`
                                    : "Non enregistré"
                            }
                        >
                            <div className="flex gap-2">
                                <Input
                                    readOnly
                                    value={data.webhook_url}
                                    className="font-mono text-xs"
                                    onFocus={(e) => e.target.select()}
                                />
                                <Button
                                    variant="secondary"
                                    onClick={() =>
                                        navigator.clipboard?.writeText(
                                            data.webhook_url,
                                        )
                                    }
                                    title="Copier"
                                >
                                    <Copy className="h-4 w-4" />
                                </Button>
                            </div>
                        </Field>
                        <div className="flex flex-wrap gap-2">
                            <Button
                                variant="secondary"
                                onClick={subscribe}
                                disabled={!!busy}
                            >
                                <Link2 className="h-4 w-4" />{" "}
                                {busy === "webhook"
                                    ? "Enregistrement…"
                                    : "Enregistrer le webhook chez Speedaf"}
                            </Button>
                            <Button
                                variant="secondary"
                                onClick={syncNow}
                                disabled={!!busy}
                            >
                                <RefreshCw
                                    className={`h-4 w-4 ${busy === "sync" ? "animate-spin" : ""}`}
                                />{" "}
                                Synchroniser maintenant
                            </Button>
                        </div>
                        <div className="grid grid-cols-4 gap-2 text-center">
                            {[
                                [
                                    "En cours",
                                    data.stats.active,
                                    "text-blue-700",
                                ],
                                [
                                    "Livrés",
                                    data.stats.delivered,
                                    "text-emerald-700",
                                ],
                                [
                                    "Retours",
                                    data.stats.returned,
                                    "text-orange-700",
                                ],
                                [
                                    "Annulés",
                                    data.stats.cancelled,
                                    "text-slate-500",
                                ],
                            ].map(([label, n, cls]) => (
                                <div
                                    key={label}
                                    className="rounded-xl bg-slate-50 px-2 py-2"
                                >
                                    <div className={`text-lg font-bold ${cls}`}>
                                        {n}
                                    </div>
                                    <div className="text-[11px] font-semibold uppercase text-slate-400">
                                        {label}
                                    </div>
                                </div>
                            ))}
                        </div>
                        <p className="text-xs text-slate-400">
                            Dernière synchronisation :{" "}
                            {formatDateTime(data.last_synced_at)}
                        </p>
                    </div>
                </Card>
            </div>

            <Card
                title="Correspondance des statuts"
                subtitle="Statut Speedaf → statut de livraison Lavfast (Paramètres → Statuts). « Ne rien changer » conserve le statut actuel."
            >
                <div className="grid gap-x-6 gap-y-2 md:grid-cols-2">
                    {o.speedaf_statuses.map((s) => (
                        <div
                            key={s.code}
                            className="flex items-center gap-3 border-b border-slate-50 py-1.5"
                        >
                            <div className="min-w-0 flex-1">
                                <div className="truncate text-sm font-semibold text-slate-700">
                                    {s.label}
                                </div>
                                <div className="font-mono text-[11px] text-slate-400">
                                    {s.code}
                                </div>
                            </div>
                            <div className="w-52 shrink-0">
                                <Select
                                    className="h-9"
                                    value={form.status_mapping?.[s.code] || ""}
                                    onChange={(e) =>
                                        set("status_mapping", {
                                            ...form.status_mapping,
                                            [s.code]: e.target.value || null,
                                        })
                                    }
                                >
                                    <option value="">
                                        — Ne rien changer —
                                    </option>
                                    {meta.statuses.map((st) => (
                                        <option key={st.id} value={st.code}>
                                            {st.name}
                                        </option>
                                    ))}
                                </Select>
                            </div>
                        </div>
                    ))}
                </div>
            </Card>

            <div className="flex justify-end">
                <Button type="submit" disabled={!!busy}>
                    <Save className="h-4 w-4" />{" "}
                    {busy === "save" ? "Enregistrement…" : "Enregistrer"}
                </Button>
            </div>
        </form>
    );
}
