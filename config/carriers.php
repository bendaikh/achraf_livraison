<?php

use App\Services\Carriers\OzonCarrier;
use App\Services\Carriers\SiftCarrier;
use App\Services\Carriers\SpeedafCarrier;

/*
| Sociétés de livraison (T11). Each entry = a class implementing
| App\Services\Carriers\CarrierInterface. Order = display order in the quick-ship popup
| and the Commandes bulk bar.
*/
return [
    'drivers' => [
        'speedaf' => SpeedafCarrier::class,
        'ozon' => OzonCarrier::class,
        'sift' => SiftCarrier::class,
    ],
];
