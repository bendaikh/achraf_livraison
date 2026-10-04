import { ArrowRight } from 'lucide-react';
import { Link } from 'react-router-dom';

export default function IntegrationsBanner() {
    return (
        <section className="overflow-hidden rounded-2xl border border-blue-100 bg-gradient-to-r from-blue-50 via-sky-50 to-indigo-50 shadow-sm shadow-blue-100/40">
            <div className="flex flex-col items-start justify-between gap-4 px-4 py-5 sm:flex-row sm:items-center sm:px-6">
                <div className="max-w-xl">
                    <h2 className="text-base font-bold text-slate-900 sm:text-lg">
                        Optimisez vos livraisons avec nos intégrations
                    </h2>
                    <p className="mt-1 text-sm font-medium text-slate-500">
                        Connectez Ozon Express, Speedaf et Shopify pour automatiser vos flux.
                    </p>
                </div>

                <div className="flex w-full flex-col items-stretch gap-3 sm:w-auto sm:flex-row sm:items-center sm:justify-end">
                    <svg viewBox="0 0 120 56" className="mx-auto hidden h-14 w-28 sm:mx-0 md:block" aria-hidden>
                        <rect x="8" y="22" width="70" height="26" rx="6" fill="#2563eb" />
                        <rect x="18" y="14" width="36" height="14" rx="4" fill="#60a5fa" />
                        <circle cx="28" cy="48" r="7" fill="#1e293b" />
                        <circle cx="68" cy="48" r="7" fill="#1e293b" />
                        <rect x="84" y="30" width="28" height="18" rx="4" fill="#93c5fd" />
                        <path d="M0 54h120" stroke="#94a3b8" strokeWidth="2" opacity="0.4" />
                    </svg>
                    <Link
                        to="/integrations/ozon"
                        className="inline-flex items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-blue-600/25 transition hover:bg-blue-700"
                    >
                        Configurer les intégrations
                        <ArrowRight className="h-4 w-4" />
                    </Link>
                </div>
            </div>
        </section>
    );
}
