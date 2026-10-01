import { useEffect, useState } from 'react';
import api, { errorMessage, fieldErrors } from '../lib/api';
import { useMeta } from '../context/MetaContext';
import { Alert, Button, Card, Field, Input, PageHeader, Spinner } from '../components/ui';

export default function Settings() {
    const meta = useMeta();
    const [form, setForm] = useState(null);
    const [errors, setErrors] = useState({});
    const [msg, setMsg] = useState(null);
    const [error, setError] = useState(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        api.get('/settings')
            .then(({ data }) => setForm(data.data))
            .catch((e) => setError(errorMessage(e)));
    }, []);

    async function save(e) {
        e.preventDefault();
        setSaving(true);
        setMsg(null);
        setError(null);
        setErrors({});
        try {
            const tariffs = Object.fromEntries(Object.entries(form.default_tariffs).map(([k, v]) => [k, Number(v || 0)]));
            const { data } = await api.put('/settings', { ...form, default_tariffs: tariffs });
            setForm(data.data);
            setMsg('Paramètres enregistrés.');
            meta.reload();
        } catch (err) {
            setErrors(fieldErrors(err));
            setError(errorMessage(err));
        } finally {
            setSaving(false);
        }
    }

    if (!form) return error ? <Alert>{error}</Alert> : <Spinner />;

    return (
        <form onSubmit={save} className="space-y-4">
            <PageHeader title="Paramètres de la société" subtitle="Informations générales et tarifs livreur par défaut." />
            <div className="grid gap-4 lg:grid-cols-2">
                <Card title="Société">
                    <div className="space-y-3">
                        <Field label="Nom de la société" error={errors.company_name}>
                            <Input value={form.company_name || ''} onChange={(e) => setForm({ ...form, company_name: e.target.value })} />
                        </Field>
                        <Field
                            label="Alerte « à confirmer depuis trop longtemps » (heures)"
                            error={errors.confirmation_alert_hours}
                            hint="Utilisé dans la zone « À traiter » du tableau de bord."
                        >
                            <Input
                                type="number"
                                min="1"
                                value={form.confirmation_alert_hours || ''}
                                onChange={(e) => setForm({ ...form, confirmation_alert_hours: Number(e.target.value) })}
                            />
                        </Field>
                    </div>
                </Card>
                <Card title="Tarifs livreur par défaut" subtitle="Préremplis automatiquement à la création d’un livreur (modifiables par livreur).">
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        {meta.missionTypes.map((t) => (
                            <Field key={t.value} label={t.label} error={errors[`default_tariffs.${t.value}`]}>
                                <div className="relative">
                                    <Input
                                        type="number"
                                        min="0"
                                        step="0.5"
                                        value={form.default_tariffs?.[t.value] ?? ''}
                                        onChange={(e) => setForm({ ...form, default_tariffs: { ...form.default_tariffs, [t.value]: e.target.value } })}
                                        className="pr-10"
                                    />
                                    <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs font-semibold text-slate-400">DH</span>
                                </div>
                            </Field>
                        ))}
                    </div>
                </Card>
            </div>
            <Alert>{error}</Alert>
            <Alert type="success">{msg}</Alert>
            <div className="flex justify-end">
                <Button type="submit" disabled={saving}>
                    {saving ? 'Enregistrement…' : 'Enregistrer'}
                </Button>
            </div>
        </form>
    );
}
