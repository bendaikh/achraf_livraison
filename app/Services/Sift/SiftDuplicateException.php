<?php

namespace App\Services\Sift;

use App\Models\SiftShipment;

/** The order already has an active Sift parcel: never create a second one. */
class SiftDuplicateException extends SiftException
{
    public function __construct(public readonly SiftShipment $shipment)
    {
        parent::__construct('Cette commande est déjà envoyée à Sift (n° '.($shipment->tracking_number ?: $shipment->parcel_id).').');
    }
}
