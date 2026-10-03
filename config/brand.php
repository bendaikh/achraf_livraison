<?php

/*
| Product identity (T1 branding). The product is "Lav'Fast" with "Flow" as its second line;
| written on one line it is always "Lav'Fast Flow". Domain: lavfast-flow.com.
| Kept out of APP_NAME on purpose so a server .env can never change the visible brand.
*/
return [
    'name' => "Lav'Fast",
    'suffix' => 'Flow',
    'title' => "Lav'Fast Flow",
    'domain' => 'lavfast-flow.com',
];
