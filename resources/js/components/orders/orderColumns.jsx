import { Ban, Package } from 'lucide-react';
import { ColorBadge, StatusBadge } from '../ui/Badge';
import { formatDH, formatDateTime } from '../../lib/format';
import SyncStatusBadge from '../shopify/SyncStatusBadge';

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
    { key: 'amount', label: 'À encaisser', title: 'Montant à encaisser' },
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
            return (
                <span className="inline-flex items-center gap-1">
                    <span className="font-semibold text-slate-800">{order.reference}</span>
                    {order.is_draft || order.flow_state === 'draft' ? (
                        <span className="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-amber-800">Brouillon</span>
                    ) : null}
                    {order.lifecycle_status === 'cancelled' || order.cancel_reason ? (
                        <span className="rounded bg-rose-50 px-1.5 py-0.5 text-[10px] font-bold uppercase text-rose-700">Annulée</span>
                    ) : null}
                    <SyncStatusBadge
                        status={order.shopify_sync_status}
                        error={order.shopify_sync_error}
                        onRetry={order.shopify_sync_status === 'failed' && meta?.retryShopify && (order.creation_key || meta?.canEditItems) ? () => meta.retryShopify(order) : undefined}
                    />
                </span>
            );
        case 'product':
            return (
                <span className="flex max-w-[130px] items-baseline gap-1" title={order.product_name || ''}>
                    <span className="truncate font-medium text-slate-700">{order.product_name || '—'}</span>
                    {order.quantity > 1 ? <span className="shrink-0 text-[11px] font-semibold text-slate-400">×{order.quantity}</span> : null}
                    {order.has_out_of_stock ? <span className="shrink-0 rounded bg-rose-50 px-1 text-[10px] font-bold text-rose-600">Rupture</span> : null}
                </span>
            );
        case 'customer':
            return (
                <span className="flex max-w-[115px] items-center gap-1">
                    {order.client_blocked ? (
                        <span title={`Client bloqué : ${order.client_blocked.reason}`} aria-label="Client bloqué" className="shrink-0 text-rose-600">
                            <Ban className="h-3.5 w-3.5" />
                        </span>
                    ) : null}
                    <span className={`truncate font-medium ${order.client_blocked ? 'text-rose-700' : 'text-slate-700'}`}>{order.customer_name}</span>
                </span>
            );
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
            return (
                <span className={`font-semibold ${Number(order.amount_due ?? 0) <= 0 ? 'text-emerald-600' : 'text-slate-800'}`}>
                    À encaisser : {formatDH(order.amount_due ?? 0)}
                </span>
            );
        case 'payment': {
            const method = order.payment_method;
            if (method === 'paye') {
                return <span className="rounded-md bg-emerald-50 px-1.5 py-0.5 text-[11px] font-semibold text-emerald-700">Payée en ligne</span>;
            }
            if (method === 'partial') {
                return <span className="rounded-md bg-amber-50 px-1.5 py-0.5 text-[11px] font-semibold text-amber-700">Partiellement payée</span>;
            }
            return <span className="rounded-md bg-slate-100 px-1.5 py-0.5 text-[11px] font-semibold text-slate-600">Paiement à la livraison</span>;
        }
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
