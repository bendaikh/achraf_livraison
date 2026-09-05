import { topDrivers } from '../../data/dashboard';

export default function TopDrivers() {
    return (
        <section className="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm shadow-slate-200/40">
            <div className="mb-4">
                <h2 className="text-base font-bold text-slate-900">Top livreurs aujourd&apos;hui</h2>
                <p className="text-xs font-medium text-slate-400">Classement des performances</p>
            </div>

            <ul className="space-y-3">
                {topDrivers.map((driver, index) => (
                    <li key={driver.id} className="flex items-center gap-3">
                        <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-bold text-slate-500">
                            {index + 1}
                        </div>
                        <div
                            className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white"
                            style={{ backgroundColor: driver.color }}
                        >
                            {driver.avatar}
                        </div>
                        <div className="min-w-0 flex-1">
                            <div className="truncate text-sm font-semibold text-slate-800">
                                {driver.name}
                            </div>
                            <div className="text-[11px] font-medium text-slate-400">
                                {driver.deliveries} livraisons
                            </div>
                        </div>
                        <div className="rounded-full bg-emerald-50 px-2 py-1 text-xs font-bold text-emerald-700">
                            {driver.score}%
                        </div>
                    </li>
                ))}
            </ul>
        </section>
    );
}
