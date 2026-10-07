import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, ArrowRight, Check, Users } from 'lucide-react';
import api, { errorMessage } from '../../lib/api';
import { Alert, Button, Card, Field, Select, Spinner, Textarea } from '../../components/ui';

const STEPS = [
    'Informations',
    'Numéro WhatsApp',
    'Destinataires',
    'Template',
    'Variables',
    'Aperçu',
    'Envoi',
];

const emptyAudience = () => ({ logic: 'and', rules: [] });

function ruleLabel(rule) {
    if (!rule?.type) return '—';
    if (rule.type === 'tag') return `Tag (${rule.operator || 'has_any'}) #${(rule.tag_ids || []).join(',')}`;
    if (rule.type === 'vehicle') return `Véhicule ${[rule.brand, rule.model, rule.year_min && `${rule.year_min}–${rule.year_max || ''}`].filter(Boolean).join(' ')}`;
    if (rule.type === 'group') return `Groupe #${(rule.group_ids || []).join(',')}`;
    if (rule.type === 'segment') return `Segment #${rule.segment_id}`;
    if (rule.type === 'city') return `Ville ${rule.value}`;
    if (rule.type === 'orders_count') return `Commandes ${rule.op} ${rule.value}`;
    if (rule.type === 'product_purchased') return `A acheté ${rule.title || rule.product_id}`;
    if (rule.type === 'product_not_purchased') return `N’a pas acheté ${rule.title || rule.product_id}`;
    return rule.type;
}

export default function CampaignWizard() {
    const { id } = useParams();
    const navigate = useNavigate();
    const editing = Boolean(id);
    const [step, setStep] = useState(0);
    const [meta, setMeta] = useState(null);
    const [tags, setTags] = useState([]);
    const [groups, setGroups] = useState([]);
    const [segments, setSegments] = useState([]);
    const [templates, setTemplates] = useState([]);
    const [audienceInfo, setAudienceInfo] = useState(null);
    const [preview, setPreview] = useState(null);
    const [summary, setSummary] = useState(null);
    const [error, setError] = useState(null);
    const [msg, setMsg] = useState(null);
    const [busy, setBusy] = useState(false);
    const [clientSearch, setClientSearch] = useState('');
    const [clientHits, setClientHits] = useState([]);
    const [form, setForm] = useState({
        name: '',
        description: '',
        whatsapp_account_id: '',
        whatsapp_template_id: '',
        audience_definition: emptyAudience(),
        variable_mapping: [],
        manual_phone_keys: [],
        exclusion_phone_keys: [],
        send_mode: 'draft',
        scheduled_at: '',
        exclude_recently_contacted: false,
        confirm: false,
    });

    const setField = (k, v) => setForm((f) => ({ ...f, [k]: v }));

    const loadMeta = useCallback(async () => {
        const [{ data: m }, { data: t }, { data: s }, { data: g }] = await Promise.all([
            api.get('/whatsapp/campaigns/meta'),
            api.get('/client-tags'),
            api.get('/audience-segments'),
            api.get('/clients/summary'),
        ]);
        setMeta(m.data);
        setTags(t.data || []);
        setSegments(s.data || []);
        setGroups(g.groups || []);
    }, []);

    useEffect(() => {
        (async () => {
            try {
                await loadMeta();
                if (editing) {
                    const { data } = await api.get(`/whatsapp/campaigns/${id}`);
                    const c = data.data;
                    setForm((f) => ({
                        ...f,
                        name: c.name || '',
                        description: c.description || '',
                        whatsapp_account_id: c.whatsapp_account_id || '',
                        whatsapp_template_id: c.whatsapp_template_id || '',
                        audience_definition: c.audience_definition || emptyAudience(),
                        variable_mapping: c.variable_mapping || [],
                        manual_phone_keys: c.manual_phone_keys || [],
                        exclusion_phone_keys: c.exclusion_phone_keys || [],
                    }));
                }
            } catch (e) {
                setError(errorMessage(e));
            }
        })();
    }, [editing, id, loadMeta]);

    useEffect(() => {
        if (!form.whatsapp_account_id) {
            setTemplates([]);
            return;
        }
        api.get(`/whatsapp/campaigns/accounts/${form.whatsapp_account_id}/templates`)
            .then(({ data }) => setTemplates(data.data || []))
            .catch((e) => setError(errorMessage(e)));
    }, [form.whatsapp_account_id]);

    // Debounced audience count
    useEffect(() => {
        if (step < 2) return undefined;
        const t = setTimeout(async () => {
            try {
                const { data } = await api.post('/whatsapp/campaigns/audience/preview', {
                    audience_definition: form.audience_definition,
                    manual_phone_keys: form.manual_phone_keys,
                    exclusion_phone_keys: form.exclusion_phone_keys,
                    exclude_recently_contacted: form.exclude_recently_contacted,
                    per_page: 10,
                });
                setAudienceInfo(data.data);
            } catch (e) {
                setAudienceInfo(null);
                setError(errorMessage(e));
            }
        }, 400);
        return () => clearTimeout(t);
    }, [step, form.audience_definition, form.manual_phone_keys, form.exclusion_phone_keys, form.exclude_recently_contacted]);

    const selectedTemplate = useMemo(
        () => templates.find((t) => String(t.id) === String(form.whatsapp_template_id)),
        [templates, form.whatsapp_template_id],
    );

    useEffect(() => {
        if (!selectedTemplate) return;
        const n = selectedTemplate.variables_count || 0;
        setForm((f) => {
            const mapping = [...(f.variable_mapping || [])];
            while (mapping.length < n) {
                mapping.push({ slot: mapping.length + 1, source: mapping.length === 0 ? 'client_name' : 'fixed', value: '' });
            }
            return { ...f, variable_mapping: mapping.slice(0, Math.max(n, mapping.length)) };
        });
    }, [selectedTemplate?.id]);

    function addRule(rule) {
        setForm((f) => ({
            ...f,
            audience_definition: {
                ...f.audience_definition,
                rules: [...(f.audience_definition.rules || []), rule],
            },
        }));
    }

    function removeRule(idx) {
        setForm((f) => ({
            ...f,
            audience_definition: {
                ...f.audience_definition,
                rules: (f.audience_definition.rules || []).filter((_, i) => i !== idx),
            },
        }));
    }

    async function searchClients() {
        try {
            const { data } = await api.get('/whatsapp/campaigns/search-clients', { params: { q: clientSearch } });
            setClientHits(data.data || []);
        } catch (e) {
            setError(errorMessage(e));
        }
    }

    async function saveDraft() {
        setBusy(true);
        setError(null);
        try {
            const payload = {
                name: form.name,
                description: form.description,
                whatsapp_account_id: form.whatsapp_account_id || null,
                whatsapp_template_id: form.whatsapp_template_id || null,
                audience_definition: form.audience_definition,
                variable_mapping: form.variable_mapping,
                manual_phone_keys: form.manual_phone_keys,
                exclusion_phone_keys: form.exclusion_phone_keys,
            };
            let campaignId = id;
            if (editing) {
                await api.put(`/whatsapp/campaigns/${id}`, payload);
            } else {
                const { data } = await api.post('/whatsapp/campaigns', payload);
                campaignId = data.data.id;
            }
            setMsg('Brouillon enregistré.');
            return campaignId;
        } catch (e) {
            setError(errorMessage(e));
            return null;
        } finally {
            setBusy(false);
        }
    }

    async function loadPreview() {
        if (!form.whatsapp_template_id) return;
        try {
            const { data } = await api.post('/whatsapp/campaigns/preview-message', {
                whatsapp_template_id: form.whatsapp_template_id,
                variable_mapping: form.variable_mapping,
                audience_definition: form.audience_definition,
                manual_phone_keys: form.manual_phone_keys,
            });
            setPreview(data.data);
        } catch (e) {
            setError(errorMessage(e));
        }
    }

    async function goNext() {
        setError(null);
        if (step === 0 && !form.name.trim()) {
            setError('Le nom est obligatoire.');
            return;
        }
        if (step === 1 && !form.whatsapp_account_id) {
            setError('Choisissez un numéro WhatsApp.');
            return;
        }
        if (step === 3 && !form.whatsapp_template_id) {
            setError('Choisissez un template approuvé.');
            return;
        }
        if (step === 5) {
            await loadPreview();
        }
        if (step === 6) {
            const campaignId = await saveDraft();
            if (!campaignId) return;
            const { data } = await api.get(`/whatsapp/campaigns/${campaignId}/confirm-summary`, {
                params: { exclude_recently_contacted: form.exclude_recently_contacted ? 1 : 0 },
            });
            setSummary(data.data);
        }
        if (step < STEPS.length - 1) setStep((s) => s + 1);
    }

    async function confirmSend() {
        if (!form.confirm) {
            setError('Cochez la confirmation avant l’envoi.');
            return;
        }
        setBusy(true);
        setError(null);
        try {
            const campaignId = await saveDraft();
            if (!campaignId) return;
            await api.post(`/whatsapp/campaigns/${campaignId}/send`, {
                mode: form.send_mode,
                scheduled_at: form.send_mode === 'schedule' ? form.scheduled_at : null,
                exclude_recently_contacted: form.exclude_recently_contacted,
                confirm: true,
            });
            navigate(`/whatsapp/campagnes/${campaignId}`);
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setBusy(false);
        }
    }

    if (!meta) return error ? <Alert>{error}</Alert> : <Spinner />;

    return (
        <div className="space-y-4">
            <button type="button" onClick={() => navigate('/whatsapp/campagnes')} className="inline-flex items-center gap-1 text-sm font-semibold text-slate-500 hover:text-slate-800">
                <ArrowLeft className="h-4 w-4" /> Campagnes
            </button>
            <div>
                <h1 className="text-xl font-bold text-slate-900 sm:text-2xl">{editing ? 'Modifier la campagne' : 'Créer une campagne'}</h1>
                <p className="mt-1 text-sm text-slate-500">Assistant en 7 étapes · fuseau {meta.timezone}</p>
            </div>
            <Alert type="success">{msg}</Alert>
            <Alert>{error}</Alert>

            <div className="flex flex-wrap gap-1.5">
                {STEPS.map((label, i) => (
                    <button
                        key={label}
                        type="button"
                        onClick={() => setStep(i)}
                        className={`rounded-full px-2.5 py-1 text-[11px] font-semibold ${i === step ? 'bg-emerald-600 text-white' : i < step ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}
                    >
                        {i + 1}. {label}
                    </button>
                ))}
            </div>

            {step === 0 && (
                <Card title="Informations" bodyClassName="space-y-3 p-4">
                    <Field label="Nom de la campagne">
                        <input className="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" value={form.name} onChange={(e) => setField('name', e.target.value)} />
                    </Field>
                    <Field label="Description interne">
                        <Textarea value={form.description} onChange={(e) => setField('description', e.target.value)} rows={3} />
                    </Field>
                </Card>
            )}

            {step === 1 && (
                <Card title="Numéro WhatsApp connecté" bodyClassName="space-y-3 p-4">
                    <Select value={form.whatsapp_account_id} onChange={(e) => setField('whatsapp_account_id', e.target.value)}>
                        <option value="">— Choisir —</option>
                        {(meta.accounts || []).map((a) => (
                            <option key={a.id} value={a.id} disabled={!a.connected}>
                                {a.name} · {a.phone_number} {a.connected ? '' : '(déconnecté)'}
                            </option>
                        ))}
                    </Select>
                </Card>
            )}

            {step === 2 && (
                <Card title="Destinataires (audience)" bodyClassName="space-y-4 p-4">
                    <div className="flex flex-wrap items-center gap-3 rounded-xl border border-emerald-100 bg-emerald-50/60 px-3 py-2 text-sm">
                        <Users className="h-4 w-4 text-emerald-700" />
                        <button type="button" className="font-bold text-emerald-800 underline-offset-2 hover:underline" onClick={() => {}}>
                            Nombre de clients correspondant : {audienceInfo?.included_count ?? '…'}
                        </button>
                        <span className="text-xs text-slate-500">exclus : {audienceInfo?.excluded_count ?? 0}</span>
                    </div>
                    {audienceInfo?.excluded_reasons && Object.keys(audienceInfo.excluded_reasons).length > 0 ? (
                        <ul className="text-xs text-slate-500">
                            {Object.entries(audienceInfo.excluded_reasons).map(([r, n]) => (
                                <li key={r}>{r} : {n}</li>
                            ))}
                        </ul>
                    ) : null}

                    <div className="flex flex-wrap gap-2">
                        <Select defaultValue="" onChange={(e) => {
                            const op = e.target.value;
                            if (!op) return;
                            if (op === 'tag') addRule({ type: 'tag', operator: 'has_any', tag_ids: tags[0] ? [tags[0].id] : [] });
                            if (op === 'group') addRule({ type: 'group', operator: 'in', group_ids: groups[0] ? [groups[0].id] : [] });
                            if (op === 'segment') addRule({ type: 'segment', segment_id: segments[0]?.id });
                            if (op === 'city') addRule({ type: 'city', op: 'contains', value: '' });
                            if (op === 'orders') addRule({ type: 'orders_count', op: 'gte', value: 3 });
                            if (op === 'vehicle' && meta.vehicles_enabled) addRule({ type: 'vehicle', brand: 'Peugeot', model: '208', year_min: 2020, year_max: 2025 });
                            if (op === 'product') addRule({ type: 'product_purchased', title: '' });
                            if (op === 'not_product') addRule({ type: 'product_not_purchased', title: '' });
                            e.target.value = '';
                        }}>
                            <option value="">+ Ajouter un critère</option>
                            <option value="tag">Tag client</option>
                            <option value="group">Groupe</option>
                            <option value="segment">Segment dynamique</option>
                            <option value="city">Ville</option>
                            <option value="orders">Nb commandes</option>
                            <option value="product">A acheté produit</option>
                            <option value="not_product">N’a jamais acheté</option>
                            {meta.vehicles_enabled ? <option value="vehicle">Véhicule</option> : null}
                        </Select>
                        <Select value={form.audience_definition.logic || 'and'} onChange={(e) => setForm((f) => ({ ...f, audience_definition: { ...f.audience_definition, logic: e.target.value } }))}>
                            <option value="and">Combiner en ET</option>
                            <option value="or">Combiner en OU</option>
                        </Select>
                    </div>

                    <ul className="space-y-2">
                        {(form.audience_definition.rules || []).map((rule, idx) => (
                            <li key={idx} className="rounded-xl border border-slate-200 p-3 text-sm">
                                <div className="mb-2 flex items-center justify-between">
                                    <span className="font-semibold text-slate-700">{ruleLabel(rule)}</span>
                                    <button type="button" className="text-xs text-rose-600" onClick={() => removeRule(idx)}>Retirer</button>
                                </div>
                                {rule.type === 'tag' && (
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        <Select value={rule.operator} onChange={(e) => {
                                            const rules = [...form.audience_definition.rules];
                                            rules[idx] = { ...rule, operator: e.target.value };
                                            setForm((f) => ({ ...f, audience_definition: { ...f.audience_definition, rules } }));
                                        }}>
                                            <option value="has">Contient le tag</option>
                                            <option value="not_has">Ne contient pas le tag</option>
                                            <option value="has_any">Au moins un de ces tags</option>
                                            <option value="has_all">Tous ces tags</option>
                                        </Select>
                                        <Select multiple value={(rule.tag_ids || []).map(String)} onChange={(e) => {
                                            const ids = Array.from(e.target.selectedOptions).map((o) => Number(o.value));
                                            const rules = [...form.audience_definition.rules];
                                            rules[idx] = { ...rule, tag_ids: ids };
                                            setForm((f) => ({ ...f, audience_definition: { ...f.audience_definition, rules } }));
                                        }} className="min-h-[5rem]">
                                            {tags.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                                        </Select>
                                    </div>
                                )}
                                {rule.type === 'vehicle' && (
                                    <div className="grid gap-2 sm:grid-cols-4">
                                        {['brand', 'model'].map((k) => (
                                            <input key={k} className="rounded-lg border border-slate-200 px-2 py-1.5 text-sm" placeholder={k} value={rule[k] || ''} onChange={(e) => {
                                                const rules = [...form.audience_definition.rules];
                                                rules[idx] = { ...rule, [k]: e.target.value };
                                                setForm((f) => ({ ...f, audience_definition: { ...f.audience_definition, rules } }));
                                            }} />
                                        ))}
                                        <input type="number" className="rounded-lg border border-slate-200 px-2 py-1.5 text-sm" placeholder="Année min" value={rule.year_min || ''} onChange={(e) => {
                                            const rules = [...form.audience_definition.rules];
                                            rules[idx] = { ...rule, year_min: e.target.value ? Number(e.target.value) : null };
                                            setForm((f) => ({ ...f, audience_definition: { ...f.audience_definition, rules } }));
                                        }} />
                                        <input type="number" className="rounded-lg border border-slate-200 px-2 py-1.5 text-sm" placeholder="Année max" value={rule.year_max || ''} onChange={(e) => {
                                            const rules = [...form.audience_definition.rules];
                                            rules[idx] = { ...rule, year_max: e.target.value ? Number(e.target.value) : null };
                                            setForm((f) => ({ ...f, audience_definition: { ...f.audience_definition, rules } }));
                                        }} />
                                    </div>
                                )}
                                {(rule.type === 'city' || rule.type === 'product_purchased' || rule.type === 'product_not_purchased') && (
                                    <input className="w-full rounded-lg border border-slate-200 px-2 py-1.5 text-sm" value={rule.value || rule.title || ''} onChange={(e) => {
                                        const rules = [...form.audience_definition.rules];
                                        rules[idx] = rule.type === 'city' ? { ...rule, value: e.target.value } : { ...rule, title: e.target.value };
                                        setForm((f) => ({ ...f, audience_definition: { ...f.audience_definition, rules } }));
                                    }} />
                                )}
                                {rule.type === 'orders_count' && (
                                    <div className="flex gap-2">
                                        <Select value={rule.op} onChange={(e) => {
                                            const rules = [...form.audience_definition.rules];
                                            rules[idx] = { ...rule, op: e.target.value };
                                            setForm((f) => ({ ...f, audience_definition: { ...f.audience_definition, rules } }));
                                        }}>
                                            <option value="gte">≥</option>
                                            <option value="lte">≤</option>
                                            <option value="eq">=</option>
                                        </Select>
                                        <input type="number" className="rounded-lg border border-slate-200 px-2 py-1.5 text-sm" value={rule.value} onChange={(e) => {
                                            const rules = [...form.audience_definition.rules];
                                            rules[idx] = { ...rule, value: Number(e.target.value) };
                                            setForm((f) => ({ ...f, audience_definition: { ...f.audience_definition, rules } }));
                                        }} />
                                    </div>
                                )}
                                {rule.type === 'group' && (
                                    <Select value={String((rule.group_ids || [])[0] || '')} onChange={(e) => {
                                        const rules = [...form.audience_definition.rules];
                                        rules[idx] = { ...rule, group_ids: [Number(e.target.value)] };
                                        setForm((f) => ({ ...f, audience_definition: { ...f.audience_definition, rules } }));
                                    }}>
                                        {groups.map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}
                                    </Select>
                                )}
                                {rule.type === 'segment' && (
                                    <Select value={String(rule.segment_id || '')} onChange={(e) => {
                                        const rules = [...form.audience_definition.rules];
                                        rules[idx] = { ...rule, segment_id: Number(e.target.value) };
                                        setForm((f) => ({ ...f, audience_definition: { ...f.audience_definition, rules } }));
                                    }}>
                                        {segments.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                                    </Select>
                                )}
                            </li>
                        ))}
                    </ul>

                    <div className="rounded-xl border border-dashed border-slate-200 p-3">
                        <p className="mb-2 text-xs font-semibold uppercase text-slate-400">Clients spécifiques</p>
                        <div className="flex gap-2">
                            <input className="flex-1 rounded-lg border border-slate-200 px-2 py-1.5 text-sm" placeholder="Nom, téléphone, email…" value={clientSearch} onChange={(e) => setClientSearch(e.target.value)} />
                            <Button variant="secondary" onClick={searchClients}>Rechercher</Button>
                        </div>
                        <ul className="mt-2 space-y-1 text-sm">
                            {clientHits.map((c) => (
                                <li key={c.key} className="flex items-center justify-between">
                                    <span>{c.name} · {c.phone}</span>
                                    <button type="button" className="text-xs font-semibold text-emerald-700" onClick={() => {
                                        if (!form.manual_phone_keys.includes(c.key)) setField('manual_phone_keys', [...form.manual_phone_keys, c.key]);
                                    }}>Ajouter</button>
                                </li>
                            ))}
                        </ul>
                        {form.manual_phone_keys.length > 0 && (
                            <div className="mt-2 flex flex-wrap gap-1">
                                {form.manual_phone_keys.map((k) => (
                                    <button key={k} type="button" className="rounded-md bg-slate-100 px-2 py-0.5 text-xs" onClick={() => setField('manual_phone_keys', form.manual_phone_keys.filter((x) => x !== k))}>
                                        {k} ×
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>

                    {audienceInfo?.clients?.length ? (
                        <div className="text-xs text-slate-500">
                            Aperçu : {audienceInfo.clients.map((c) => c.name || c.key).join(', ')}
                            {audienceInfo.included_count > audienceInfo.clients.length ? '…' : ''}
                        </div>
                    ) : null}
                </Card>
            )}

            {step === 3 && (
                <Card title="Template disponible" bodyClassName="space-y-3 p-4">
                    <Select value={form.whatsapp_template_id} onChange={(e) => setField('whatsapp_template_id', e.target.value)}>
                        <option value="">— Template APPROVED —</option>
                        {templates.map((t) => (
                            <option key={t.id} value={t.id}>{t.name} ({t.language}) · {t.variables_count || 0} var.</option>
                        ))}
                    </Select>
                    {selectedTemplate ? (
                        <pre className="whitespace-pre-wrap rounded-xl bg-slate-50 p-3 text-sm text-slate-700">{selectedTemplate.body_text}</pre>
                    ) : null}
                </Card>
            )}

            {step === 4 && (
                <Card title="Variables du template" bodyClassName="space-y-3 p-4">
                    {(form.variable_mapping || []).map((m, i) => (
                        <div key={i} className="grid gap-2 sm:grid-cols-3">
                            <div className="text-sm font-semibold text-slate-600 self-center">{`{{${i + 1}}}`}</div>
                            <Select value={m.source} onChange={(e) => {
                                const mapping = [...form.variable_mapping];
                                mapping[i] = { ...m, source: e.target.value };
                                setField('variable_mapping', mapping);
                            }}>
                                {(meta.variable_sources || []).map((s) => <option key={s.key} value={s.key}>{s.label}</option>)}
                            </Select>
                            {(m.source === 'fixed' || m.source === 'promo_code') && (
                                <input className="rounded-lg border border-slate-200 px-2 py-1.5 text-sm" value={m.value || ''} onChange={(e) => {
                                    const mapping = [...form.variable_mapping];
                                    mapping[i] = { ...m, value: e.target.value };
                                    setField('variable_mapping', mapping);
                                }} placeholder="Valeur" />
                            )}
                        </div>
                    ))}
                    {(form.variable_mapping || []).length === 0 && <p className="text-sm text-slate-400">Ce template n’a pas de variables body.</p>}
                </Card>
            )}

            {step === 5 && (
                <Card title="Aperçu personnalisé" bodyClassName="space-y-3 p-4">
                    <Button variant="secondary" onClick={loadPreview}>Rafraîchir l’aperçu</Button>
                    <div className="rounded-2xl border border-emerald-100 bg-emerald-50/50 p-4 text-sm text-slate-800">
                        {preview?.preview || 'Chargez l’aperçu avec un client exemple de l’audience.'}
                    </div>
                    {preview?.context ? (
                        <p className="text-xs text-slate-500">Exemple : {preview.context.client_name} · {preview.context.vehicle_brand} {preview.context.vehicle_model}</p>
                    ) : null}
                    <p className="text-sm font-semibold text-slate-700">Destinataires ciblés : {audienceInfo?.included_count ?? '—'}</p>
                </Card>
            )}

            {step === 6 && (
                <Card title="Envoi" bodyClassName="space-y-4 p-4">
                    <div className="flex flex-wrap gap-3">
                        {[
                            ['now', 'Envoyer maintenant'],
                            ['schedule', 'Programmer'],
                            ['draft', 'Garder en brouillon'],
                        ].map(([v, label]) => (
                            <label key={v} className={`cursor-pointer rounded-xl border px-3 py-2 text-sm font-semibold ${form.send_mode === v ? 'border-emerald-500 bg-emerald-50 text-emerald-800' : 'border-slate-200'}`}>
                                <input type="radio" className="mr-2" checked={form.send_mode === v} onChange={() => setField('send_mode', v)} />
                                {label}
                            </label>
                        ))}
                    </div>
                    {form.send_mode === 'schedule' && (
                        <Field label={`Date / heure (${meta.timezone})`}>
                            <input type="datetime-local" className="rounded-xl border border-slate-200 px-3 py-2 text-sm" value={form.scheduled_at} onChange={(e) => setField('scheduled_at', e.target.value)} />
                        </Field>
                    )}
                    <label className="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" checked={form.exclude_recently_contacted} onChange={(e) => setField('exclude_recently_contacted', e.target.checked)} />
                        Exclure les clients contactés récemment (règle société)
                    </label>
                    {summary && (
                        <div className="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm space-y-1">
                            <p><strong>{summary.campaign?.name}</strong></p>
                            <p>Numéro : {summary.campaign?.account?.phone_number}</p>
                            <p>Template : {summary.campaign?.template?.name}</p>
                            <p>Destinataires : {summary.included_count} · Exclus : {summary.excluded_count}</p>
                            {summary.recently_contacted?.count > 0 ? (
                                <p className="text-amber-700">Attention : {summary.recently_contacted.count} client(s) déjà contactés récemment.</p>
                            ) : null}
                        </div>
                    )}
                    <label className="flex items-center gap-2 text-sm font-semibold text-slate-800">
                        <input type="checkbox" checked={form.confirm} onChange={(e) => setField('confirm', e.target.checked)} />
                        Confirmer l’envoi de la campagne
                    </label>
                    <Button disabled={busy || (form.send_mode !== 'draft' && !form.confirm)} onClick={confirmSend}>
                        <Check className="h-4 w-4" />
                        {form.send_mode === 'draft' ? 'Enregistrer le brouillon' : form.send_mode === 'schedule' ? 'Programmer la campagne' : 'Confirmer l’envoi de la campagne'}
                    </Button>
                </Card>
            )}

            <div className="flex justify-between">
                <Button variant="secondary" disabled={step === 0} onClick={() => setStep((s) => s - 1)}>
                    <ArrowLeft className="h-4 w-4" /> Précédent
                </Button>
                {step < STEPS.length - 1 ? (
                    <Button onClick={goNext} disabled={busy}>
                        Suivant <ArrowRight className="h-4 w-4" />
                    </Button>
                ) : (
                    <Link to="/whatsapp/campagnes" className="text-sm font-semibold text-slate-500">Retour à la liste</Link>
                )}
            </div>
        </div>
    );
}
