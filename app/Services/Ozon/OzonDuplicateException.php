<?php

namespace App\Services\Ozon;

use App\Models\OzonShipment;

/** The order already has an active Ozon parcel: never create a second one. */
class OzonDuplicateException extends OzonException
{
    public function __construct(public readonly OzonShipment $shipment)
    {
        parent::__construct("Cette commande est déjà envoyée à Ozon (n° {$shipment->tracking_number}).");
    }
}
