const STYLES = {
    synced: 'bg-emerald-50 text-emerald-700',
    pending: 'bg-amber-50 text-amber-800',
    failed: 'bg-rose-50 text-rose-700',
    conflict: 'bg-violet-50 text-violet-700',
};

const LABELS = {
    synced: 'Synchronisé',
    pending: 'En attente',
    failed: 'Échec',
    conflict: 'Conflit',
    echo: 'Synchronisé',
};

/** Shopify sync state. Failed shows the error and an optional retry. Conflict links to the journal. */
export default function SyncStatusBadge({ status, error, onRetry, journalHref = '/integrations/shopify' }) {
    if (!status) return null;
    const key = status === 'echo' ? 'synced' : status;
    const style = STYLES[key] || STYLES.pending;

    return (
        <span className={`inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[11px] font-semibold ${style}`} title={status === 'failed' ? error || 'Échec de synchronisation' : undefined}>
            {LABELS[status] || status}
            {status === 'failed' && onRetry ? (
            <button type="button" onClick={(e) => { e.preventDefault(); e.stopPropagation(); onRetry(); }} className="underline">
                Réessayer
            </button>
            ) : null}
            {status === 'conflict' ? (
                <a href={journalHref} className="underline">
                    Journal
                </a>
            ) : null}
        </span>
    );
}
