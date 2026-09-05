import { Link } from 'react-router-dom';

export default function NotFound() {
    return (
        <div className="rounded-2xl border border-slate-200 bg-white px-6 py-16 text-center shadow-sm">
            <h1 className="text-3xl font-bold text-slate-900">404</h1>
            <p className="mt-2 text-sm font-medium text-slate-500">Cette page n&apos;existe pas.</p>
            <Link
                to="/"
                className="mt-6 inline-flex rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700"
            >
                Retour au tableau de bord
            </Link>
        </div>
    );
}
