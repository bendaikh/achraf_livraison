import { Package } from 'lucide-react';
import { ColorBadge, StatusBadge } from '../ui/Badge';
import { formatDH, formatDateTime } from '../../lib/format';

/** Columns available in the Commandes list (UI layout, not business data). */
export const ORDER_COLUMNS = [
    { key: 'reference', label: 'Commande' },
    { key: 'product', label: 'Produit / miniature' },
    { key: 'customer', label: 'Client' },
    { key: 'phone', label: 'Téléphone' },
    { key: 'city', label: 'Ville' },
    { key: 'address', label: 'Adresse' },
    { key: 'amount', label: 'Montant' },
    { key: 'payment', label: 'Paiement' },
    { key: 'status', label: 'Statut' },
    { key: 'confirmation', label: 'Confirmation' },
    { key: 'driver', label: 'Livreur / transporteur' },
    { key: 'assigned_user', label: 'Utilisateur assigné' },
    { key: 'source', label: 'Source' },
    { key: 'date', label: 'Date' },
];

export const DEFAULT_COLUMN_PREFS = {
    desktop: ['reference', 'product', 'customer', 'phone', 'city', 'amount', 'status', 'confirmation', 'driver', 'date'],
    mobile: ['customer', 'city', 'amount', 'status'],
};

export const MOBILE_MAX = 5;

export function renderCell(key, order, meta) {
    switch (key) {
        case 'reference':
            return <span className="font-semibold text-slate-800">{order.reference}</span>;
        case 'product':
            return (
                <div className="flex items-center gap-2">
                    <div className="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-slate-100 text-slate-400">
                        {order.product_image ? <img src={order.product_image} alt="" className="h-full w-full object-cover" /> : <Package className="h-4 w-4" />}
                    </div>
                    <div className="min-w-0">
                        <div className="max-w-[180px] truncate font-medium text-slate-700">{order.product_name || '—'}</div>
                        <div className="text-[11px] text-slate-400">Qté {order.quantity}</div>
                    </div>
                </div>
            );
        case 'customer':
            return <span className="font-medium text-slate-700">{order.customer_name}</span>;
        case 'phone':
            return order.customer_phone || '—';
        case 'city':
            return order.city || '—';
        case 'address':
            return <span className="block max-w-[220px] truncate">{order.address || '—'}</span>;
        case 'amount':
            return <span className="font-semibold text-slate-800">{formatDH(order.amount)}</span>;
        case 'payment':
            return meta.paymentMap[order.payment_method]?.label || order.payment_method || '—';
        case 'status':
            return <StatusBadge status={order.delivery_status} />;
        case 'confirmation': {
            const c = meta.confirmationMap[order.confirmation_status];
            const label = c?.label || order.confirmation_status_label;
            return label ? <ColorBadge color={c?.color || order.confirmation_status_color} label={label} /> : '—';
        }
        case 'driver':
            return order.driver?.name || order.carrier || '—';
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
