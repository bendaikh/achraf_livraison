import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { PhoneCall, ClipboardCheck, Truck, Bike, Radar, Wallet, ArrowLeftRight, Undo2, Repeat, RotateCcw, PackageX, Circle, RefreshCw } from 'lucide-react';

// Icons usable by config/centre.php cards (explicit map keeps the bundle tree-shaken).
const Icons = { PhoneCall, ClipboardCheck, Truck, Bike, Radar, Wallet, ArrowLeftRight, Undo2, Repeat, RotateCcw, PackageX, Circle, RefreshCw };
import api, { errorMessage } from '../lib/api';
import { formatTime } from '../lib/format';
import { Alert, Button, PageHeader, Spinner } from '../components/ui';

/** Page « Centre » — work hubs with real counters (cards registry: config/centre.php). */
export default function Centre() {
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [loading, setLoading] = useState(false);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const { data: res } = await api.get('/centre');
            setData(res);
            setError(null);
        } catch (e) {
            setError(errorMessage(e));
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        load();
        const t = setInterval(load, 60000);
        return () => clearInterval(t);
    }, [load]);

    return (
        <div className="space-y-4">
            <PageHeader
                title="Centre"
                subtitle={data ? `Vue de travail en temps réel · mis à jour à ${formatTime(data.generated_at)}` : 'Vue de travail en temps réel'}
                actions={
                    <Button variant="secondary" onClick={load} disabled={loading}>
                        <Icons.RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} /> Actualiser
                    </Button>
                }
            />
            <Alert>{error}</Alert>
            {!data ? (
                <Spinner />
            ) : (
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                    {data.cards.map((card) => {
                        const Icon = Icons[card.icon] || Icons.Circle;
                        const breakdown = card.breakdown ? Object.entries(card.breakdown).filter(([, v]) => v > 0) : [];
                        return (
                            <Link
                                key={card.key}
                                to={card.to || '#'}
                                className="group flex flex-col rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm shadow-slate-200/40 transition hover:-translate-y-0.5 hover:border-blue-200 hover:shadow-md focus:outline-none focus-visible:ring-4 focus-visible:ring-blue-500/20"
                                aria-label={`${card.title} : ${card.count ?? 0}`}
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl" style={{ backgroundColor: `${card.color}14`, color: card.color }}>
                                        <Icon className="h-5 w-5" strokeWidth={2} />
                                    </span>
                                    <span className={`text-3xl font-extrabold tabular-nums ${card.count ? 'text-slate-900' : 'text-slate-300'}`}>{card.count ?? 0}</span>
                                </div>
                                <div className="mt-3 text-base font-bold text-slate-900 group-hover:text-blue-700">{card.title}</div>
                                <p className="mt-0.5 text-xs leading-relaxed text-slate-500">{card.description}</p>
                                {breakdown.length ? (
                                    <div className="mt-2 flex flex-wrap gap-1">
                                        {breakdown.map(([label, v]) => (
                                            <span key={label} className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600">
                                                {label} {v}
                                            </span>
                                        ))}
                                    </div>
                                ) : null}
                                <span className="mt-auto pt-3 text-xs font-semibold text-blue-600 opacity-0 transition group-hover:opacity-100">Ouvrir →</span>
                            </Link>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
