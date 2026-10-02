import { useEffect, useState } from 'react';
import { Star } from 'lucide-react';
import api, { errorMessage, fieldErrors } from '../../lib/api';
import { useMeta } from '../../context/MetaContext';
import { formatDH, todayISO } from '../../lib/format';
import { Alert, Button, Drawer, Field, Input, Select, Textarea } from '../ui';
import PartnerForm from '../partners/PartnerForm';

const NEW_PARTNER = '__new__';

const CONFIG = {
    ramassage: {
        title: 'Créer un ramassage',
        category: 'ramassage',
        partnerLabel: 'Partenaire / lieu de ramassage',
        contact: 'Client / contact *',
        contactPh: 'Nom du client ou de la boutique',
        items: 'Description des articles',
        withCash: true,
    },
    depot_partenaire: {
        title: 'Créer un dépôt partenaire',
        category: 'depot',
        partnerLabel: 'Partenaire',
        contact: 'Nom affiché / destination *',
        contactPh: 'Ex : Agence Ozone, Speedaf…',
        items: 'Articles / colis',
        withCash: false,
    },
};

const empty = () => ({
    logistics_partner_id: '',
    contact_name: '',
    phone: '',
    address: '',
    city: '',
    items_description: '',
    quantity: 1,
    scheduled_date: todayISO(),
    time_slot: '',
    driver_id: '',
    note: '',
    cash_amount: '',
    cash_direction: 'collect',
});

/** Drawer creating a Ramassage or Dépôt partenaire mission (price snapshot from the driver's tariff). */
export default function MissionDrawer({ type, open, onClose, onCreated }) {
    const meta = useMeta();
    const cfg = CONFIG[type] || CONFIG.ramassage;
    const [form, setForm] = useState(empty);
    const [errors, setErrors] = useState({});
    const [error, setError] = useState(null);
    const [saving, setSaving] = useState(false);
    const [tariffs, setTariffs] = useState({});
    const [partners, setPartners] = useState([]);
    const [quickOpen, setQuickOpen] = useState(false);

    const loadPartners = () =>
        api
            .get('/logistics-partners', { params: { category: cfg.category, active: 1 } })
            .then(({ data }) => {
                setPartners(data.data);
                return data.data;
            })
            .catch(() => []);

    useEffect(() => {
        if (!open) return;
        setForm(empty());
        setErrors({});
        setError(null);
        api.get('/drivers', { params: { active: 1 } })
            .then(({ data }) => setTariffs(Object.fromEntries(data.data.map((d) => [d.id, d.tariffs]))))
            .catch(() => {});
        setPartners([]);
        setQuickOpen(false);
        loadPartners();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, type]);

    const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));
    const selectedPartner = partners.find((p) => String(p.id) === String(form.logistics_partner_id)) || null;
    const overridden = selectedPartner && ['phone', 'city', 'address'].some((k) => (form[k] || '') !== (selectedPartner[k] || ''));

    // Picking a saved partner pre-fills the mission; fields stay editable for this mission only.
    function applyPartner(p) {
        setForm((f) => ({
            ...f,
            logistics_partner_id: p ? String(p.id) : '',
            contact_name: p ? p.name : f.contact_name,
            phone: p ? p.phone || '' : f.phone,
            city: p ? p.city || '' : f.city,
            address: p ? p.address || '' : f.address,
        }));
    }

    function choosePartner(e) {
        const value = e.target.value;
        if (value === NEW_PARTNER) {
            setQuickOpen(true);
            return;
        }
        applyPartner(partners.find((p) => String(p.id) === value) || null);
    }

    const price = form.driver_id ? tariffs[form.driver_id]?.[type] : null;

    async function submit(e) {
        e?.preventDefault();
        setSaving(true);
        setErrors({});
        setError(null);
        try {
            const payload = {
                ...form,
                type,
                logistics_partner_id: form.logistics_partner_id ? Number(form.logistics_partner_id) : null,
                driver_id: form.driver_id ? Number(form.driver_id) : null,
                quantity: Number(form.quantity || 1),
                cash_amount: cfg.withCash && form.cash_amount !== '' ? Number(form.cash_amount) : null,
                cash_direction: cfg.withCash && form.cash_amount !== '' ? form.cash_direction : null,
            };
            const { data } = await api.post('/missions', payload);
            onCreated?.(data.data);
        } catch (err) {
            setErrors(fieldErrors(err));
            setError(errorMessage(err));
        } finally {
            setSaving(false);
        }
    }

    return (
        <>
            <Drawer
                open={open}
                onClose={quickOpen ? () => {} : onClose}
                title={cfg.title}
                footer={
                    <div className="flex items-center justify-between gap-2">
                        <span className="text-xs font-medium text-slate-500">
                            {price !== null && price !== undefined ? `Tarif livreur appliqué : ${formatDH(price)}` : 'Aucun livreur sélectionné'}
                        </span>
                        <div className="flex gap-2">
                            <Button variant="secondary" onClick={onClose}>
                                Annuler
                            </Button>
                            <Button onClick={submit} disabled={saving}>
                                {saving ? 'Création…' : 'Créer la mission'}
                            </Button>
                        </div>
                    </div>
                }
            >
                <form onSubmit={submit} className="grid grid-cols-2 gap-3">
                    <Field
                        label={cfg.partnerLabel}
                        error={errors.logistics_partner_id}
                        className="col-span-2"
                        hint={
                            selectedPartner
                                ? 'Coordonnées préremplies depuis la fiche partenaire — modifiables pour cette mission uniquement.'
                                : partners.length === 0
                                  ? 'Aucun partenaire enregistré pour ce type : ajoutez-en un via « + Nouveau partenaire ».'
                                  : null
                        }
                    >
                        <Select value={form.logistics_partner_id} onChange={choosePartner}>
                            <option value="">Choisir un partenaire…</option>
                            {partners.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.is_favorite ? '★ ' : ''}
                                    {p.name}
                                    {p.city ? ` — ${p.city}` : ''}
                                </option>
                            ))}
                            <option value={NEW_PARTNER}>+ Nouveau partenaire</option>
                        </Select>
                    </Field>
                    {selectedPartner ? (
                        <div className="col-span-2 -mt-1 flex flex-wrap items-center gap-2 rounded-xl bg-blue-50/60 px-3 py-2 text-xs text-slate-600">
                            {selectedPartner.is_favorite ? <Star className="h-3.5 w-3.5 fill-amber-400 text-amber-400" /> : null}
                            <span className="font-semibold text-slate-800">{selectedPartner.name}</span>
                            {selectedPartner.contact_name ? <span>· {selectedPartner.contact_name}</span> : null}
                            {overridden ? (
                                <button
                                    type="button"
                                    className="ml-auto font-semibold text-blue-600 hover:underline"
                                    onClick={() => applyPartner(selectedPartner)}
                                >
                                    Rétablir les coordonnées du partenaire
                                </button>
                            ) : null}
                        </div>
                    ) : null}
                    <Field label={cfg.contact} error={errors.contact_name} className="col-span-2">
                        <Input value={form.contact_name} onChange={set('contact_name')} placeholder={cfg.contactPh} required={!selectedPartner} />
                    </Field>
                    <Field label="Téléphone" error={errors.phone}>
                        <Input value={form.phone} onChange={set('phone')} />
                    </Field>
                    <Field label="Ville" error={errors.city}>
                        <Input value={form.city} onChange={set('city')} />
                    </Field>
                    <Field label="Adresse" error={errors.address} className="col-span-2">
                        <Input value={form.address} onChange={set('address')} />
                    </Field>
                    <Field label={cfg.items} error={errors.items_description} className="col-span-2">
                        <Textarea value={form.items_description} onChange={set('items_description')} rows={2} />
                    </Field>
                    <Field label="Quantité" error={errors.quantity}>
                        <Input type="number" min="1" value={form.quantity} onChange={set('quantity')} />
                    </Field>
                    <Field label="Date souhaitée" error={errors.scheduled_date}>
                        <Input type="date" value={form.scheduled_date} onChange={set('scheduled_date')} />
                    </Field>
                    <Field label="Heure / créneau" error={errors.time_slot}>
                        <Input value={form.time_slot} onChange={set('time_slot')} placeholder="Ex : 10:00 - 12:00" />
                    </Field>
                    <Field label="Livreur à affecter" error={errors.driver_id}>
                        <Select value={form.driver_id} onChange={set('driver_id')}>
                            <option value="">— Non affecté —</option>
                            {meta.drivers.map((d) => (
                                <option key={d.id} value={d.id}>
                                    {d.name}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    {cfg.withCash ? (
                        <>
                            <Field label="Montant (DH, optionnel)" error={errors.cash_amount}>
                                <Input type="number" min="0" step="0.01" value={form.cash_amount} onChange={set('cash_amount')} />
                            </Field>
                            <Field label="Sens du montant" error={errors.cash_direction}>
                                <Select value={form.cash_direction} onChange={set('cash_direction')}>
                                    <option value="collect">À récupérer</option>
                                    <option value="remit">À remettre</option>
                                </Select>
                            </Field>
                        </>
                    ) : null}
                    <Field label="Note" error={errors.note} className="col-span-2">
                        <Textarea value={form.note} onChange={set('note')} rows={2} />
                    </Field>
                    <div className="col-span-2">
                        <Alert>{error}</Alert>
                    </div>
                    <button type="submit" className="hidden" />
                </form>
            </Drawer>
            <PartnerForm
                open={open && quickOpen}
                defaultType={cfg.category}
                onClose={() => setQuickOpen(false)}
                onSaved={async (p) => {
                    setQuickOpen(false);
                    const list = await loadPartners();
                    const fresh = list.find((x) => x.id === p.id) || p;
                    // A partner created as inactive or for the other category can't be used here.
                    if (fresh.is_active && (fresh.type === 'both' || fresh.type === cfg.category)) applyPartner(fresh);
                }}
            />
        </>
    );
}
