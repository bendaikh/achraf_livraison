import { ArrowDownToLine, ArrowUpFromLine } from 'lucide-react';
import ProductThumb from '../products/ProductThumb';

const STATE_CLS = { pending: 'bg-slate-100 text-slate-600', with_driver: 'bg-amber-50 text-amber-700', at_depot: 'bg-emerald-50 text-emerald-700', delivered: 'bg-emerald-50 text-emerald-700', returned: 'bg-slate-100 text-slate-600' };

function Row({ item }) {
    return (
        <div className="flex items-center gap-2.5 py-1.5">
            <ProductThumb src={item.image_url} alt={item.title} size="h-8 w-8" />
            <div className="min-w-0 flex-1 text-sm">
                <div className="truncate font-semibold text-slate-800">
                    {item.quantity} × {item.title}
                </div>
                <div className="text-[11px] text-slate-400">{[item.variant_title, item.sku].filter(Boolean).join(' · ')}</div>
            </div>
            <span className={`shrink-0 rounded-md px-1.5 py-0.5 text-[10px] font-bold ${STATE_CLS[item.state] || ''}`}>{item.state_label}</span>
        </div>
    );
}

/** What the driver must pick up / deliver, with custody state. */
export default function SavItems({ sav }) {
    return (
        <div className="space-y-2">
            <div>
                <div className="flex items-center gap-1 text-[11px] font-bold uppercase tracking-wide text-slate-400">
                    <ArrowDownToLine className="h-3 w-3" /> À récupérer
                </div>
                {sav.pickup.map((i) => (
                    <Row key={i.id} item={i} />
                ))}
            </div>
            {sav.deliver.length ? (
                <div>
                    <div className="flex items-center gap-1 text-[11px] font-bold uppercase tracking-wide text-emerald-600">
                        <ArrowUpFromLine className="h-3 w-3" /> À remettre (nouveau produit)
                    </div>
                    {sav.deliver.map((i) => (
                        <Row key={i.id} item={i} />
                    ))}
                </div>
            ) : null}
        </div>
    );
}
