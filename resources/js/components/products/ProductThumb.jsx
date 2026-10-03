import { useState } from 'react';
import { Package } from 'lucide-react';

/** Product photo (Shopify CDN URL) with a clean placeholder when missing or broken. */
export default function ProductThumb({ src, alt = '', size = 'h-9 w-9', className = '' }) {
    const [broken, setBroken] = useState(false);
    return (
        <div className={`flex shrink-0 items-center justify-center overflow-hidden rounded-lg bg-slate-100 text-slate-400 ring-1 ring-inset ring-slate-200/70 ${size} ${className}`}>
            {src && !broken ? (
                <img src={src} alt={alt} loading="lazy" className="h-full w-full object-cover" onError={() => setBroken(true)} />
            ) : (
                <Package className="h-1/2 w-1/2" strokeWidth={1.6} />
            )}
        </div>
    );
}

export function StockBadge({ item }) {
    if (item?.inventory_tracked === null || item?.inventory_tracked === undefined) return null;
    if (!item.inventory_tracked) return <span className="text-[11px] font-medium text-slate-400">Stock non suivi</span>;
    const qty = Number(item.inventory_quantity || 0);
    return qty > 0 ? (
        <span className="text-[11px] font-semibold text-emerald-700">{qty} en stock</span>
    ) : (
        <span className="text-[11px] font-semibold text-rose-600">Rupture de stock</span>
    );
}
