import { useCallback, useEffect, useState } from 'react';
import { Pencil, Plus, Search, UserCheck, UserX } from 'lucide-react';
import api, { errorMessage } from '../lib/api';
import { formatDH } from '../lib/format';
import { Alert, Button, Card, Checkbox, Drawer, EmptyState, Field, Input, PageHeader, Select, Spinner } from '../components/ui';
import CommissionLines from '../components/team/CommissionLines';

const EMPTY = { name: '', email: '', phone: '', role: 'user', service_id: '', manager_id: '', is_active: true, password: '', commission_mode: 'none', commission_value: '', commission_trigger: '' };

/** Utilisateurs (T6): back-office users, service, responsable, access, remuneration and commissions. */
export default function Users() {
    const [rows, setRows] = useState(null);
    const [options, setOptions] = useState(null);
    const [q, setQ] = useState('');
    const [error, setError] = useState(null);
    const [msg, setMsg] = useState(null);
    const [editing, setEditing] = useState(null);

    const load = useCallback(async () => {
        try {
            const { data } = await api.get('/users', { params: { q: q || undefined } });
            setRows(data.data);
            setOptions(data.options);
        } catch (e) {
            setError(errorMessage(e));
        }
    }, [q]);

    useEffect(() => {
        const t = setTimeout(load, 250);
        return () => clearTimeout(t);
    }, [load]);

    return (
        <div className="space-y-4">
            <PageHeader
                title="Utilisateurs"
                subtitle="Équipe back-office : rôle, service, responsable, accès et rémunération."
                actions={
                    <Button onClick={() => setEditing({ ...EMPTY })}>
                        <Plus className="h-4 w-4" /> Nouvel utilisateur
                    </Button>
                }
            />
            <Alert type="success">{msg}</Alert>
            <Alert>{error}</Alert>
            <Card bodyClassName="p-3 sm:p-4">
                <div className="relative">
                    <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Nom, email, téléphone…" className="pl-9" />
                </div>
            </Card>
            {!rows ? (
                <Spinner />
            ) : rows.length === 0 ? (
                <Card>
                    <EmptyState>Aucun utilisateur.</EmptyState>
                </Card>
            ) : (
                <Card bodyClassName="p-0">
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-left text-sm">
                            <thead>
                                <tr className="border-b border-slate-100 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                                    <th className="px-4 py-2.5">Nom</th>
                                    <th className="px-2 py-2.5">Contact</th>
                                    <th className="px-2 py-2.5">Rôle</th>
                                    <th className="px-2 py-2.5">Service</th>
                                    <th className="px-2 py-2.5">Responsable</th>
                                    <th className="px-2 py-2.5">Rémunération</th>
                                    <th className="px-2 py-2.5">État</th>
                                    <th className="px-2 py-2.5" />
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((u) => (
                                    <tr key={u.id} className="cursor-pointer border-b border-slate-50 last:border-0 hover:bg-slate-50/70" onClick={() => setEditing(u)}>
                                        <td className="px-4 py-2 font-semibold text-slate-800">{u.name}</td>
                                        <td className="px-2 py-2 text-xs text-slate-500">
                                            <div>{u.email}</div>
                                            <div>{u.phone || '—'}</div>
                                        </td>
                                        <td className="px-2 py-2 text-slate-600">{u.role_label}</td>
                                        <td className="px-2 py-2 text-slate-600">{u.service_name || '—'}</td>
                                        <td className="px-2 py-2 text-slate-600">{u.manager_name || '—'}</td>
                                        <td className="px-2 py-2 text-xs text-slate-600">
                                            {u.commission_mode === 'none' ? '—' : `${u.commission_mode_label} · ${u.commission_mode === 'percent' ? `${u.commission_value} %` : formatDH(u.commission_value)}`}
                                        </td>
                                        <td className="px-2 py-2">
                                            {u.is_active ? (
                                                <span className="inline-flex items-center gap-1 text-xs font-semibold text-emerald-700">
                                                    <UserCheck className="h-3.5 w-3.5" /> Actif
                                                </span>
                                            ) : (
                                                <span className="inline-flex items-center gap-1 text-xs font-semibold text-slate-400">
                                                    <UserX className="h-3.5 w-3.5" /> Inactif
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-2 py-2 text-right">
                                            <Pencil className="inline h-4 w-4 text-slate-300" />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>
            )}
            {editing ? (
                <UserDrawer
                    user={editing}
                    options={options}
                    onClose={() => setEditing(null)}
                    onSaved={(m) => {
                        setMsg(m);
                        setEditing(null);
                        load();
                    }}
                />
            ) : null}
        </div>
    );
}

function UserDrawer({ user, options, onClose, onSaved }) {
    const isNew = !user.id;
    const [form, setForm] = useState(() => ({ ...EMPTY, ...user, service_id: user.service_id || '', manager_id: user.manager_id || '', commission_value: user.commission_value ?? '', commission_trigger: user.commission_trigger || '', password: '' }));
    const [errors, setErrors] = useState({});
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);
    const [detail, setDetail] = useState(null);

    useEffect(() => {
        if (!isNew) api.get(`/users/${user.id}`).then(({ data }) => setDetail(data)).catch(() => {});
    }, [isNew, user.id]);

    const set = (k, v) => setForm((f) => ({ ...f, [k]: v }));
    const mode = options?.commission_modes?.find((m) => m.value === form.commission_mode);

    async function save() {
        setBusy(true);
        setErrors({});
        setError(null);
        const payload = {
            ...form,
            service_id: form.service_id || null,
            manager_id: form.manager_id || null,
            commission_value: form.commission_mode === 'none' || form.commission_value === '' ? null : Number(form.commission_value),
            commission_trigger: form.commission_trigger || null,
            password: form.password || undefined,
        };
        try {
            const { data } = isNew ? await api.post('/users', payload) : await api.put(`/users/${user.id}`, payload);
            onSaved(data.message);
        } catch (e) {
            setErrors(e?.response?.data?.errors || {});
            setError(errorMessage(e));
        } finally {
            setBusy(false);
        }
    }

    const err = (k) => errors[k]?.[0];

    return (
        <Drawer
            open
            wide
            onClose={onClose}
            title={isNew ? 'Nouvel utilisateur' : user.name}
            footer={
                <div className="flex justify-end gap-2">
                    <Button variant="secondary" onClick={onClose}>
                        Annuler
                    </Button>
                    <Button onClick={save} disabled={busy}>
                        {busy ? 'Enregistrement…' : 'Enregistrer'}
                    </Button>
                </div>
            }
        >
            <div className="space-y-5">
                <Alert>{error}</Alert>
                <section className="grid gap-3 sm:grid-cols-2">
                    <Field label="Nom *" error={err('name')}>
                        <Input value={form.name} onChange={(e) => set('name', e.target.value)} />
                    </Field>
                    <Field label="Téléphone" error={err('phone')}>
                        <Input value={form.phone || ''} onChange={(e) => set('phone', e.target.value)} />
                    </Field>
                    <Field label="Email *" error={err('email')}>
                        <Input type="email" value={form.email} onChange={(e) => set('email', e.target.value)} />
                    </Field>
                    <Field label={isNew ? 'Mot de passe *' : 'Nouveau mot de passe'} hint={isNew ? '8 caractères minimum' : 'Laisser vide pour ne pas changer'} error={err('password')}>
                        <Input type="password" value={form.password} onChange={(e) => set('password', e.target.value)} autoComplete="new-password" />
                    </Field>
                </section>

                <section>
                    <h3 className="mb-2 text-xs font-bold uppercase tracking-wide text-slate-400">Accès</h3>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <Field label="Rôle" error={err('role')} hint={form.role === 'user' ? 'Agent : Confirmation, Commandes, WhatsApp, ses stats' : 'Accès complet à l’administration'}>
                            <Select value={form.role} onChange={(e) => set('role', e.target.value)} disabled={user.role === 'superadmin'}>
                                {user.role === 'superadmin' ? <option value="superadmin">Super Admin</option> : null}
                                {(options?.roles || []).map((r) => (
                                    <option key={r.value} value={r.value}>
                                        {r.label}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label="Service" error={err('service_id')}>
                            <Select value={form.service_id} onChange={(e) => set('service_id', e.target.value)}>
                                <option value="">—</option>
                                {(options?.services || []).filter((s) => s.is_active || s.id === form.service_id).map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label="Responsable hiérarchique" error={err('manager_id')}>
                            <Select value={form.manager_id} onChange={(e) => set('manager_id', e.target.value)}>
                                <option value="">—</option>
                                {(options?.managers || []).filter((m) => m.id !== user.id).map((m) => (
                                    <option key={m.id} value={m.id}>
                                        {m.name}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                    </div>
                    <label className="mt-3 flex items-center gap-2 text-sm font-medium text-slate-700">
                        <Checkbox checked={!!form.is_active} onChange={(e) => set('is_active', e.target.checked)} /> Compte actif
                    </label>
                </section>

                <section>
                    <h3 className="mb-2 text-xs font-bold uppercase tracking-wide text-slate-400">Rémunération / commissions</h3>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <Field label="Mode">
                            <Select value={form.commission_mode} onChange={(e) => set('commission_mode', e.target.value)}>
                                {(options?.commission_modes || []).map((m) => (
                                    <option key={m.value} value={m.value}>
                                        {m.label}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        {form.commission_mode !== 'none' ? (
                            <Field label={mode?.kind === 'percent' ? 'Pourcentage (%)' : 'Montant (DH)'} error={err('commission_value')}>
                                <Input type="number" min="0" step="0.01" value={form.commission_value} onChange={(e) => set('commission_value', e.target.value)} />
                            </Field>
                        ) : null}
                        {['fixed', 'percent'].includes(mode?.kind) ? (
                            <Field label="Déclenchement" hint={!form.commission_trigger && mode?.default_trigger ? `Par défaut : ${options.commission_triggers.find((t) => t.value === mode.default_trigger)?.label}` : null}>
                                <Select value={form.commission_trigger} onChange={(e) => set('commission_trigger', e.target.value)}>
                                    <option value="">Par défaut du mode</option>
                                    {(options?.commission_triggers || []).map((t) => (
                                        <option key={t.value} value={t.value}>
                                            {t.label}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                        ) : null}
                    </div>
                    <p className="mt-2 text-[11px] text-slate-400">
                        Le taux est figé au moment où la commission est générée (jamais recalculé). Commande confirmée puis annulée ou retournée : pas de commission définitive (sauf règle « à la confirmation »). Flux séparé des frais livreurs et du COD client.
                    </p>
                </section>

                {!isNew ? (
                    <section>
                        <h3 className="mb-2 text-xs font-bold uppercase tracking-wide text-slate-400">Historique des commissions</h3>
                        {detail ? (
                            <>
                                <div className="mb-2 grid grid-cols-2 gap-2 sm:grid-cols-4">
                                    {Object.entries(detail.totals || {}).map(([k, t]) => (
                                        <div key={k} className="rounded-xl bg-slate-50 px-3 py-2">
                                            <div className="text-[11px] font-semibold uppercase text-slate-400">{t.label}</div>
                                            <div className="text-sm font-bold text-slate-800">{formatDH(t.amount)}</div>
                                            <div className="text-[11px] text-slate-400">{t.count} ligne(s)</div>
                                        </div>
                                    ))}
                                </div>
                                <CommissionLines lines={detail.commissions} />
                            </>
                        ) : (
                            <Spinner />
                        )}
                    </section>
                ) : null}
            </div>
        </Drawer>
    );
}
