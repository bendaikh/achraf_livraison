import { formatDate, formatMoney, orderDisplayName, statusBadgeStyle } from './confirmationHelpers';

export default function ConfirmationOrderCard({ order, onOpen }) {
    const label = order.confirmation_status_label || order.confirmation_status;

    return (
        <button
            type="button"
            onClick={() => onOpen(order)}
            className="w-full rounded-2xl border border-slate-200/80 bg-white p-4 text-left shadow-sm shadow-slate-200/40 transition hover:border-blue-200 hover:shadow-md"
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-sm font-bold text-slate-900">{orderDisplayName(order)}</span>
                        <span
                            className="inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold"
                            style={statusBadgeStyle(order.confirmation_status_color)}
                        >
                            {label}
                        </span>
                    </div>
                    <p className="mt-1.5 truncate text-sm font-semibold text-slate-800">
                        {order.customer_name || 'Client inconnu'}
                    </p>
                    <p className="mt-0.5 truncate text-xs font-medium text-slate-500">
                        {[order.phone || '—', order.city || '—'].join(' · ')}
                    </p>
                    {order.assigned_user_name ? <p className="mt-0.5 truncate text-[11px] font-semibold text-cyan-700">Agent : {order.assigned_user_name}</p> : null}
                </div>
                <div className="shrink-0 text-right">
                    <p className="text-sm font-bold text-slate-900">
                        {formatMoney(order.total_price, order.currency)}
                    </p>
                    <p className="mt-1 text-[11px] font-medium text-slate-400">
                        {formatDate(order.shopify_created_at)}
                    </p>
                </div>
            </div>
        </button>
    );
}
