<?php

namespace App\Services\Carriers;

use App\Models\Order;

/**
 * Optional branding and UI actions on top of CarrierInterface. A carrier that does not
 * implement this still ships: the registry falls back to a coloured initial and no extras.
 *
 * Action URLs may contain "{id}" (the order id). The fiche commande resolves them;
 * bulk document URLs are called with {order_ids}.
 */
interface CarrierPresentation
{
    /** Public logo (e.g. /images/carriers/{key}.svg), or null for the coloured chip. */
    public function logoUrl(): ?string;

    /**
     * Per-order actions (Suivre, Étiquette, BL, Actualiser statut, Annuler…).
     *
     * @return list<array{key:string,label:string,method:string,url:string,confirm?:string,prompt?:string,prompt_default?:string,body?:string,ui?:string}>
     */
    public function actions(): array;

    /**
     * Bulk documents (BL, étiquettes with a format). Shown inside « Envoyer avec » and « Imprimer ».
     *
     * @return list<array{key:string,label:string,method:string,url:string,formats?:array<string,string>}>
     */
    public function documents(): array;

    /**
     * Previous parcels (cancelled), newest first. Never calls the carrier.
     *
     * @return list<array{carrier:string,carrier_label:string,tracking:?string,status_label:?string,state:?string,shipped_at:?string}>
     */
    public function history(Order $order): array;

    /** Parcels created for this company since $since (recent_rank). */
    public function recentCount(int $companyId, \DateTimeInterface $since): int;
}
