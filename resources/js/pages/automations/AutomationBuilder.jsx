import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Plus, Save, Trash2 } from 'lucide-react';
import api, { errorMessage } from '../../lib/api';
import { Alert, Button, Field, Input, PageHeader, Select, Spinner, Textarea } from '../../components/ui';

function uid(prefix) {
    return `${prefix}_${Math.random().toString(36).slice(2, 8)}`;
}

function emptyDefinition() {
    const cond = uid('cond');
    const action = uid('action');
    return {
        entry: cond,
        steps: {
            [cond]: {
                type: 'condition',
                logic: 'and',
                rules: [{ field: 'city', op: 'eq', value: '' }],
                then: action,
                else: null,
            },
            [action]: {
                type: 'action',
                action: 'internal.add_note',
                config: { note: 'Note automatique', field: 'internal_note' },
                next: null,
            },
        },
    };
}

/** Builder v1 — étapes structurées (condition / wait / action / branche). */
export default function AutomationBuilder() {
    const { id } = useParams();
    const isNew = !id || id === 'new';
    const navigate = useNavigate();
    const [catalog, setCatalog] = useState(null);
    const [templates, setTemplates] = useState([]);
    const [name, setName] = useState('');
    const [triggerType, setTriggerType] = useState('order.created');
    const [status, setStatus] = useState('draft');
    const [definition, setDefinition] = useState(emptyDefinition);
    const [loading, setLoading] = useState(!isNew);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);
    const [msg, setMsg] = useState(null);

    const stepKeys = useMemo(() => Object.keys(definition.steps || {}), [definition]);

    useEffect(() => {
        (async () => {
            try {
                const [cat, tpl] = await Promise.all([
                    api.get('/automations/catalog'),
                    api.get('/automations/templates'),
                ]);
                setCatalog(cat.data.data);
                setTemplates(tpl.data.data || []);
            } catch (e) {
                setError(errorMessage(e));
            }
        })();
    }, []);

    useEffect(() => {
        if (isNew) return undefined;
        (async () => {
            try {
                const { data } = await api.get(`/automations/${id}`);
                const a = data.data;
                setName(a.name);
                setTriggerType(a.trigger_type);
                setStatus(a.status);
                setDefinition(a.definition || emptyDefinition());
                setLoading(false);
            } catch (e) {
                setError(errorMessage(e));
                setLoading(false);
            }
        })();
        return undefined;
    }, [id, isNew]);

    function updateStep(key, patch) {
        setDefinition((d) => ({
            ...d,
            steps: { ...d.steps, [key]: { ...d.steps[key], ...patch } },
        }));
    }

    function updateRule(stepKey, ruleIndex, patch) {
        setDefinition((d) => {
            const step = d.steps[stepKey];
            const rules = [...(step.rules || [])];
            rules[ruleIndex] = { ...rules[ruleIndex], ...patch };
            return { ...d, steps: { ...d.steps, [stepKey]: { ...step, rules } } };
        });
    }

    function addStep(type) {
        const key = uid(type);
        const step =
            type === 'wait'
                ? { type: 'wait', amount: 1, unit: 'hours', recheck_conditions: true, conditions: { logic: 'and', rules: [] }, next: null }
                : type === 'condition'
                  ? { type: 'condition', logic: 'and', rules: [{ field: 'city', op: 'eq', value: '' }], then: null, else: null }
                  : {
                        type: 'action',
                        action: 'internal.add_note',
                        config: { note: '', field: 'internal_note' },
                        next: null,
                    };
        setDefinition((d) => {
            const steps = { ...d.steps, [key]: step };
            // Link last step's next/then to the new one if empty
            const keys = Object.keys(d.steps);
            if (keys.length) {
                const lastKey = keys[keys.length - 1];
                const last = { ...steps[lastKey] };
                if (last.type === 'condition' && !last.then) last.then = key;
                else if (last.type !== 'condition' && !last.next) last.next = key;
                steps[lastKey] = last;
            }
            return {
                entry: d.entry || key,
                steps,
            };
        });
    }

    function removeStep(key) {
        setDefinition((d) => {
            const steps = { ...d.steps };
            delete steps[key];
            const entry = d.entry === key ? Object.keys(steps)[0] || null : d.entry;
            return { entry, steps };
        });
    }

    async function save() {
        setSaving(true);
        setError(null);
        setMsg(null);
        const payload = {
            name: name || 'Sans titre',
            trigger_type: triggerType,
            trigger_config: {},
            definition,
            status,
        };
        try {
            if (isNew) {
                const { data } = await api.post('/automations', payload);
                setMsg('Automatisation créée.');
                navigate(`/automations/${data.data.id}/edit`, { replace: true });
            } else {
                await api.put(`/automations/${id}`, payload);
                setMsg('Enregistré (nouvelle version si le graphe a changé).');
            }
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setSaving(false);
        }
    }

    async function installTemplate(slug) {
        try {
            const { data } = await api.post('/automations/templates/install', { slug });
            navigate(`/automations/${data.data.id}/edit`, { replace: true });
        } catch (e) {
            setError(errorMessage(e));
        }
    }

    if (loading || !catalog) {
        return <Spinner />;
    }

    return (
        <div className="space-y-5">
            <PageHeader
                title={isNew ? 'Nouvelle automatisation' : 'Modifier l’automatisation'}
                subtitle="Builder v1 — QUAND / SI / ATTENDRE / ACTIONS. Variables : {{order.city}}, {{steps.xxx.field}}"
                actions={
                    <>
                        <Button variant="secondary" onClick={() => navigate('/automations')}>
                            <ArrowLeft className="h-4 w-4" /> Retour
                        </Button>
                        {!isNew ? (
                            <Link
                                to={`/automations/${id}/test`}
                                className="inline-flex h-10 items-center rounded-xl border border-slate-200 bg-white px-3.5 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                            >
                                Tester
                            </Link>
                        ) : null}
                        <Button onClick={save} disabled={saving}>
                            <Save className="h-4 w-4" /> {saving ? 'Enregistrement…' : 'Enregistrer'}
                        </Button>
                    </>
                }
            />

            {error ? <Alert type="error">{error}</Alert> : null}
            {msg ? <Alert type="success">{msg}</Alert> : null}

            {isNew && templates.length > 0 ? (
                <div className="rounded-2xl border border-slate-200/80 bg-white p-4">
                    <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Modèles</p>
                    <div className="flex flex-wrap gap-2">
                        {templates.map((t) => (
                            <button
                                key={t.slug}
                                type="button"
                                onClick={() => installTemplate(t.slug)}
                                className="rounded-xl border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                                title={t.description}
                            >
                                {t.name}
                            </button>
                        ))}
                    </div>
                </div>
            ) : null}

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="space-y-3 rounded-2xl border border-slate-200/80 bg-white p-4 lg:col-span-1">
                    <Field label="Nom">
                        <Input value={name} onChange={(e) => setName(e.target.value)} placeholder="Ex. Nouvelle commande Casablanca" />
                    </Field>
                    <Field label="QUAND — Déclencheur">
                        <Select value={triggerType} onChange={(e) => setTriggerType(e.target.value)}>
                            {(catalog.triggers || []).map((t) => (
                                <option key={t.key} value={t.key}>
                                    {t.label}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field label="Statut">
                        <Select value={status} onChange={(e) => setStatus(e.target.value)}>
                            <option value="draft">Brouillon</option>
                            <option value="active">Active</option>
                            <option value="paused">En pause</option>
                        </Select>
                    </Field>
                    <Field label="Étape d’entrée">
                        <Select
                            value={definition.entry || ''}
                            onChange={(e) => setDefinition((d) => ({ ...d, entry: e.target.value }))}
                        >
                            {stepKeys.map((k) => (
                                <option key={k} value={k}>
                                    {k}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <div className="flex flex-wrap gap-2 pt-2">
                        <Button size="sm" variant="secondary" onClick={() => addStep('condition')}>
                            <Plus className="h-3.5 w-3.5" /> SI
                        </Button>
                        <Button size="sm" variant="secondary" onClick={() => addStep('wait')}>
                            <Plus className="h-3.5 w-3.5" /> Attendre
                        </Button>
                        <Button size="sm" variant="secondary" onClick={() => addStep('action')}>
                            <Plus className="h-3.5 w-3.5" /> Action
                        </Button>
                    </div>
                </div>

                <div className="space-y-3 lg:col-span-2">
                    {stepKeys.map((key) => {
                        const step = definition.steps[key];
                        return (
                            <div key={key} className="rounded-2xl border border-slate-200/80 bg-white p-4">
                                <div className="mb-3 flex items-center justify-between gap-2">
                                    <div>
                                        <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">
                                            {step.type === 'condition'
                                                ? 'SI / SINON'
                                                : step.type === 'wait'
                                                  ? 'ATTENDRE'
                                                  : 'ACTION'}
                                        </p>
                                        <p className="font-mono text-xs text-slate-500">{key}</p>
                                    </div>
                                    <button
                                        type="button"
                                        className="rounded-lg p-1.5 text-rose-500 hover:bg-rose-50"
                                        onClick={() => removeStep(key)}
                                    >
                                        <Trash2 className="h-4 w-4" />
                                    </button>
                                </div>

                                {step.type === 'condition' ? (
                                    <div className="space-y-2">
                                        <Field label="Logique">
                                            <Select
                                                value={step.logic || 'and'}
                                                onChange={(e) => updateStep(key, { logic: e.target.value })}
                                            >
                                                <option value="and">ET (toutes)</option>
                                                <option value="or">OU (au moins une)</option>
                                            </Select>
                                        </Field>
                                        {(step.rules || []).map((rule, i) => (
                                            <div key={i} className="grid gap-2 sm:grid-cols-3">
                                                <Select
                                                    value={rule.field}
                                                    onChange={(e) => updateRule(key, i, { field: e.target.value })}
                                                >
                                                    {(catalog.condition_fields || []).map((f) => (
                                                        <option key={f.key} value={f.key}>
                                                            {f.label}
                                                        </option>
                                                    ))}
                                                </Select>
                                                <Select
                                                    value={rule.op}
                                                    onChange={(e) => updateRule(key, i, { op: e.target.value })}
                                                >
                                                    {(catalog.operators || []).map((o) => (
                                                        <option key={o.key} value={o.key}>
                                                            {o.label}
                                                        </option>
                                                    ))}
                                                </Select>
                                                {(catalog.condition_fields || []).find((f) => f.key === rule.field)?.options?.length ? (
                                                    <Select value={rule.value ?? ''} onChange={(e) => updateRule(key, i, { value: e.target.value })}>
                                                        <option value="">Choisir…</option>
                                                        {(catalog.condition_fields || []).find((f) => f.key === rule.field).options.map((opt) => (
                                                            <option key={opt.value ?? opt} value={opt.value ?? opt}>{opt.label ?? opt}</option>
                                                        ))}
                                                    </Select>
                                                ) : (
                                                    <Input
                                                        value={rule.value ?? ''}
                                                        onChange={(e) => updateRule(key, i, { value: e.target.value })}
                                                        placeholder="Valeur"
                                                    />
                                                )}
                                            </div>
                                        ))}
                                        <div className="grid gap-2 sm:grid-cols-2">
                                            <Field label="Alors →">
                                                <Select
                                                    value={step.then || ''}
                                                    onChange={(e) => updateStep(key, { then: e.target.value || null })}
                                                >
                                                    <option value="">Fin</option>
                                                    {stepKeys.filter((k) => k !== key).map((k) => (
                                                        <option key={k} value={k}>
                                                            {k}
                                                        </option>
                                                    ))}
                                                </Select>
                                            </Field>
                                            <Field label="Sinon →">
                                                <Select
                                                    value={step.else || ''}
                                                    onChange={(e) => updateStep(key, { else: e.target.value || null })}
                                                >
                                                    <option value="">Fin</option>
                                                    {stepKeys.filter((k) => k !== key).map((k) => (
                                                        <option key={k} value={k}>
                                                            {k}
                                                        </option>
                                                    ))}
                                                </Select>
                                            </Field>
                                        </div>
                                    </div>
                                ) : null}

                                {step.type === 'wait' ? (
                                    <div className="grid gap-2 sm:grid-cols-3">
                                        <Field label="Durée">
                                            <Input
                                                type="number"
                                                min={0}
                                                value={step.amount ?? 0}
                                                onChange={(e) => updateStep(key, { amount: Number(e.target.value) })}
                                            />
                                        </Field>
                                        <Field label="Unité">
                                            <Select
                                                value={step.unit || 'minutes'}
                                                onChange={(e) => updateStep(key, { unit: e.target.value })}
                                            >
                                                {(catalog.wait_units || []).map((u) => (
                                                    <option key={u.key} value={u.key}>
                                                        {u.label}
                                                    </option>
                                                ))}
                                            </Select>
                                        </Field>
                                        <Field label="Ensuite →">
                                            <Select
                                                value={step.next || ''}
                                                onChange={(e) => updateStep(key, { next: e.target.value || null })}
                                            >
                                                <option value="">Fin</option>
                                                {stepKeys.filter((k) => k !== key).map((k) => (
                                                    <option key={k} value={k}>
                                                        {k}
                                                    </option>
                                                ))}
                                            </Select>
                                        </Field>
                                    </div>
                                ) : null}

                                {step.type === 'action' ? (
                                    <div className="space-y-2">
                                        <Field label="Action">
                                            <Select
                                                value={step.action}
                                                onChange={(e) =>
                                                    updateStep(key, { action: e.target.value, config: step.config || {} })
                                                }
                                            >
                                                {(catalog.actions || []).map((a) => (
                                                    <option key={a.key} value={a.key}>
                                                        {a.label}
                                                    </option>
                                                ))}
                                            </Select>
                                        </Field>
                                        {step.action === 'order.set_confirmation_status' ? (
                                            <Field label="Statut de confirmation">
                                                <Select
                                                    value={step.config?.status_code || ''}
                                                    onChange={(e) => updateStep(key, { config: { ...(step.config || {}), status_code: e.target.value } })}
                                                >
                                                    <option value="">Choisir un statut…</option>
                                                    {((catalog.actions || []).find((a) => a.key === 'order.set_confirmation_status')?.config_schema || [])
                                                        .find((field) => field.key === 'status_code')?.options?.map((opt) => (
                                                            <option key={opt.value} value={opt.value}>{opt.label}</option>
                                                        ))}
                                                </Select>
                                            </Field>
                                        ) : null}
                                        <Field label="Config (JSON) — variables {{order.*}} / {{steps.*}}">
                                            <Textarea
                                                value={JSON.stringify(step.config || {}, null, 2)}
                                                onChange={(e) => {
                                                    try {
                                                        updateStep(key, { config: JSON.parse(e.target.value || '{}') });
                                                    } catch {
                                                        /* ignore while typing */
                                                    }
                                                }}
                                                rows={5}
                                            />
                                        </Field>
                                        <Field label="Ensuite →">
                                            <Select
                                                value={step.next || ''}
                                                onChange={(e) => updateStep(key, { next: e.target.value || null })}
                                            >
                                                <option value="">Fin</option>
                                                {stepKeys.filter((k) => k !== key).map((k) => (
                                                    <option key={k} value={k}>
                                                        {k}
                                                    </option>
                                                ))}
                                            </Select>
                                        </Field>
                                    </div>
                                ) : null}
                            </div>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}
