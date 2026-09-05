import { Eye, MoreHorizontal } from 'lucide-react';
import { recentOrders, statusLabels } from '../../data/dashboard';

export default function RecentOrders() {
    return (
        <section className="rounded-2xl border border-slate-200/80 bg-white shadow-sm shadow-slate-200/40">
            <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                <div>
                    <h2 className="text-base font-bold text-slate-900">Dernières commandes</h2>
                    <p className="text-xs font-medium text-slate-400">Activité récente des commandes</p>
                </div>
                <button
                    type="button"
                    className="text-xs font-semibold text-blue-600 hover:text-blue-700"
                >
                    Voir tout
                </button>
            </div>

            <div className="overflow-x-auto">
                <table className="min-w-full text-left text-sm">
                    <thead>
                        <tr className="border-b border-slate-100 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                            <th className="px-5 py-3">#</th>
                            <th className="px-3 py-3">Client</th>
                            <th className="px-3 py-3">Téléphone</th>
                            <th className="px-3 py-3">Ville</th>
                            <th className="px-3 py-3">Montant</th>
                            <th className="px-3 py-3">Statut</th>
                            <th className="px-3 py-3">Date</th>
                            <th className="px-5 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {recentOrders.map((order) => {
                            const status = statusLabels[order.status];

                            return (
                                <tr
                                    key={order.id}
                                    className="border-b border-slate-50 last:border-0 hover:bg-slate-50/70"
                                >
                                    <td className="whitespace-nowrap px-5 py-3.5 font-semibold text-slate-800">
                                        #{order.id}
                                    </td>
                                    <td className="whitespace-nowrap px-3 py-3.5 font-medium text-slate-700">
                                        {order.client}
                                    </td>
                                    <td className="whitespace-nowrap px-3 py-3.5 text-slate-500">
                                        {order.phone}
                                    </td>
                                    <td className="whitespace-nowrap px-3 py-3.5 text-slate-500">
                                        {order.city}
                                    </td>
                                    <td className="whitespace-nowrap px-3 py-3.5 font-semibold text-slate-800">
                                        {order.amount} DH
                                    </td>
                                    <td className="whitespace-nowrap px-3 py-3.5">
                                        <span
                                            className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset ${status.className}`}
                                        >
                                            {status.label}
                                        </span>
                                    </td>
                                    <td className="whitespace-nowrap px-3 py-3.5 text-slate-500">
                                        {order.date}
                                    </td>
                                    <td className="whitespace-nowrap px-5 py-3.5">
                                        <div className="flex items-center justify-end gap-1">
                                            <button
                                                type="button"
                                                className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
                                                aria-label="Voir"
                                            >
                                                <Eye className="h-4 w-4" />
                                            </button>
                                            <button
                                                type="button"
                                                className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
                                                aria-label="Plus"
                                            >
                                                <MoreHorizontal className="h-4 w-4" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </section>
    );
}
