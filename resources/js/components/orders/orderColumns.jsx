import { Package } from 'lucide-react';
import { ColorBadge, StatusBadge } from '../ui/Badge';
import { formatDH, formatDateTime } from '../../lib/format';

/**
 * Columns available in the Commandes list (UI layout, not business data).
 * Order = display order (T11 compact priority: photo, n°, produit, client, téléphone,
 * ville/adresse, paiement, statut, livreur/transporteur, à encaisser, expédition).
 */
export const ORDER_COLUMNS = [
    { key: 'photo', label: 'Photo' },
    { key: 'reference', label: 'Commande' },
    { key: 'product', label: 'Produit' },
    { key: 'customer', label: 'Client' },
    { key: 'phone', label: 'Téléphone' },
    { key: 'city', label: 'Ville / adresse' },
    { key: 'address', label: 'Adresse complète' },
    { key: 'payment', label: 'Paiement' },
    { key: 'status', label: 'Statut' },
    { key: 'confirmation', label: 'Confirmation' },
    { key: 'driver', label: 'Transporteur', title: 'Livreur / société de livraison' },
    { key: 'amount', label: 'À payer', title: 'Montant à encaisser' },
    { key: 'speedaf', label: 'Suivi colis' },
    { key: 'assigned_user', label: 'Utilisateur assigné' },
    { key: 'source', label: 'Source' },
    { key: 'date', label: 'Date' },
    { key: 'ship', label: 'Expédition' },
];

/** v2 = T11 compact layout (new preference key so everyone gets the new defaults once). */
export const COLUMN_PREFS_KEY = 'orders.columns.v2';

export const DEFAULT_COLUMN_PREFS = {
    desktop: ['photo', 'reference', 'product', 'customer', 'phone', 'city', 'payment', 'status', 'driver', 'amount', 'ship'],
    mobile: ['customer', 'city', 'amount', 'status'],
};

export const MOBILE_MAX = 5;

export function ProductPhoto({ order, size = 'h-8 w-8' }) {
    return (
        <div className={`flex ${size} shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-100 bg-slate-50 text-slate-300`}>
            {order.product_image ? <img src={order.product_image} alt="" loading="lazy" className="h-full w-full object-cover" /> : <Package className="h-4 w-4" />}
        </div>
    );
}

export function renderCell(key, order, meta) {
    switch (key) {
        case 'photo':
            return <ProductPhoto order={order} />;
        case 'reference':
            return <span className="font-semibold text-slate-800">{order.reference}</span>;
        case 'product':
            return (
                <span className="flex max-w-[130px] items-baseline gap-1" title={order.product_name || ''}>
                    <span className="truncate font-medium text-slate-700">{order.product_name || '—'}</span>
                    {order.quantity > 1 ? <span className="shrink-0 text-[11px] font-semibold text-slate-400">×{order.quantity}</span> : null}
                    {order.has_out_of_stock ? <span className="shrink-0 rounded bg-rose-50 px-1 text-[10px] font-bold text-rose-600">Rupture</span> : null}
                </span>
            );
        case 'customer':
            return <span className="block max-w-[105px] truncate font-medium text-slate-700">{order.customer_name}</span>;
        case 'phone':
            return order.customer_phone || '—';
        case 'city': {
            const city = order.city || '—';
            return (
                <span className="block max-w-[108px] truncate" title={[order.city, order.address].filter(Boolean).join(' · ')}>
                    <span className="font-medium text-slate-700">{city}</span>
                    {order.address ? <span className="text-slate-400"> · {order.address}</span> : null}
                </span>
            );
        }
        case 'address':
            return <span className="block max-w-[220px] truncate">{order.address || '—'}</span>;
        case 'amount':
            return order.payment_method === 'paye' ? (
                <span className="font-semibold text-emerald-600" title={`Déjà payé (${formatDH(order.amount)})`}>
                    0 DH
                </span>
            ) : (
                <span className="font-semibold text-slate-800">{formatDH(order.amount)}</span>
            );
        case 'payment':
            return order.payment_method === 'paye' ? (
                <span className="rounded-md bg-emerald-50 px-1.5 py-0.5 text-[11px] font-semibold text-emerald-700">Payé</span>
            ) : (
                <span className="rounded-md bg-slate-100 px-1.5 py-0.5 text-[11px] font-semibold text-slate-600">COD</span>
            );
        case 'status':
            return <StatusBadge status={order.delivery_status} />;
        case 'confirmation': {
            const c = meta.confirmationMap[order.confirmation_status];
            const label = c?.label || order.confirmation_status_label;
            return label ? <ColorBadge color={c?.color || order.confirmation_status_color} label={label} /> : '—';
        }
        case 'driver':
            if (order.shipment) {
                return (
                    <span className="inline-flex items-center gap-1.5">
                        <span className="h-2 w-2 rounded-full" style={{ backgroundColor: order.shipment.color }} />
                        <span className="font-medium text-slate-700">{order.shipment.carrier_label}</span>
                    </span>
                );
            }
            return order.driver?.name ? <span className="block max-w-[105px] truncate font-medium text-slate-700">{order.driver.name}</span> : order.carrier || '—';
        case 'speedaf':
            return order.shipment ? (
                <div className="leading-tight">
                    <div className="font-mono text-xs font-semibold text-slate-700">{order.shipment.tracking}</div>
                    <div className="max-w-[170px] truncate text-[11px]" style={{ color: order.shipment.color }} title={order.shipment.status_message || ''}>
                        {order.shipment.status_label}
                    </div>
                </div>
            ) : (
                '—'
            );
        case 'assigned_user':
            return order.assigned_user?.name || '—';
        case 'source':
            return order.source || '—';
        case 'date':
            return formatDateTime(order.shopify_created_at || order.created_at);
        default:
            return null;
    }
}
