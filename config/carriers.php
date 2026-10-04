<?php

use App\Services\Carriers\OzonCarrier;
use App\Services\Carriers\SpeedafCarrier;

/*
| Sociétés de livraison (T11). Each entry = a class implementing
| App\Services\Carriers\CarrierInterface. Order = display order in the quick-ship popup
| and the Commandes bulk bar. Upcoming: 'sift' => SiftCarrier::class (T8).
*/
return [
    'drivers' => [
        'speedaf' => SpeedafCarrier::class,
        'ozon' => OzonCarrier::class,
    ],
];
