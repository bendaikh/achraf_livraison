export default function AmountDueStaleNotice({ order }) {
    if (!order?.amount_due_stale) return null;

    return (
        <div className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800">
            Montant à encaisser modifié après l’envoi — vérifier le colis
        </div>
    );
}
