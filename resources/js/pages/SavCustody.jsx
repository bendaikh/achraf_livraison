import { useEffect, useState } from 'react';
import { ArrowDownToLine, ArrowUpFromLine, Phone } from 'lucide-react';
import api, { errorMessage } from '../lib/api';
import { formatDateTime } from '../lib/format';
import { Alert, Card, EmptyState, PageHeader, Spinner } from '../components/ui';
import ProductThumb from '../components/products/ProductThumb';
import SavDetailDrawer from '../components/sav/SavDetailDrawer';

function ItemRow({ i, onOpen }) {
    return (
        <button type="button" onClick={() => onOpen(i.sav_id)} className="flex w-full items-center gap-2.5 py-1.5 text-left hover:bg-slate-50">
            <ProductThumb src={i.image_url} alt={i.title} size="h-8 w-8" />
            <span className="min-w-0 flex-1 text-sm">
                <span className="block truncate font-semibold text-slate-800">
                    {i.quantity} × {i.title}
                    {i.variant_title ? ` (${i.variant_title})` : ''}
                </span>
                <span className="block text-[11px] text-slate-400">
                    {i.sav_reference} · {i.customer_name} · {i.status_label} · depuis {formatDateTime(i.since)}
                </span>
            </span>
        </button>
    );
}

/** T7 — Articles chez les livreurs: what each driver holds (to bring back / to deliver). */
export default function SavCustody() {
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [openId, setOpenId] = useState(null);
    const load = () =>
        api
            .get('/sav/custody')
            .then(({ data: d }) => setData(d.data))
            .catch((e) => setError(errorMessage(e)));
    useEffect(() => {
        load();
    }, []);

    return (
        <div className="space-y-4">
            <PageHeader title="Articles chez les livreurs" subtitle="Produits récupérés à rapporter au dépôt et nouveaux produits confiés pour les échanges." />
            <Alert>{error}</Alert>
            {!data ? (
                <Spinner />
            ) : !data.length ? (
                <Card>
                    <EmptyState>Aucun article chez les livreurs.</EmptyState>
                </Card>
            ) : (
                <div className="grid gap-4 lg:grid-cols-2">
                    {data.map((d) => (
                        <Card
                            key={d.driver_id}
                            title={d.driver_name}
                            subtitle={`${d.to_return.reduce((a, i) => a + i.quantity, 0)} à rapporter · ${d.to_deliver.reduce((a, i) => a + i.quantity, 0)} à remettre`}
                            actions={
                                d.driver_phone ? (
                                    <a href={`tel:${d.driver_phone}`} className="inline-flex items-center gap-1 text-xs font-semibold text-blue-700">
                                        <Phone className="h-3.5 w-3.5" /> {d.driver_phone}
                                    </a>
                                ) : null
                            }
                            bodyClassName="p-3 space-y-3"
                        >
                            <div>
                                <div className="flex items-center gap-1 text-[11px] font-bold uppercase tracking-wide text-amber-700">
                                    <ArrowDownToLine className="h-3 w-3" /> À rapporter au dépôt
                                </div>
                                {d.to_return.length ? d.to_return.map((i) => <ItemRow key={i.id} i={i} onOpen={setOpenId} />) : <p className="py-1 text-xs text-slate-400">Rien.</p>}
                            </div>
                            <div>
                                <div className="flex items-center gap-1 text-[11px] font-bold uppercase tracking-wide text-emerald-700">
                                    <ArrowUpFromLine className="h-3 w-3" /> Nouveaux produits à remettre
                                </div>
                                {d.to_deliver.length ? d.to_deliver.map((i) => <ItemRow key={i.id} i={i} onOpen={setOpenId} />) : <p className="py-1 text-xs text-slate-400">Rien.</p>}
                            </div>
                        </Card>
                    ))}
                </div>
            )}
            <SavDetailDrawer id={openId} onClose={() => setOpenId(null)} onChanged={load} />
        </div>
    );
}
