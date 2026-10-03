<?php

/*
| Sociétés de livraison (T11). Each entry = a class implementing
| App\Services\Carriers\CarrierInterface. Order = display order in the quick-ship popup
| and the Commandes bulk bar. Upcoming: 'sift' => SiftCarrier::class (T8), 'ozon' => OzonCarrier::class (T14).
*/
return [
    'drivers' => [
        'speedaf' => App\Services\Carriers\SpeedafCarrier::class,
    ],
];
