<?php

/*
| Centre des licences (Figma 13C) — plans, tarifs et moyens de paiement.
| Montants en unité mineure de `currency` (FCFA = XOF, 0 décimale).
| Les tarifs Business sont ceux de la maquette ; Growth / Enterprise sont des
| valeurs par défaut à valider côté métier.
*/
return [
    'currency' => env('SPARK_LICENSE_CURRENCY', 'XOF'),
    'tax_rate' => (float) env('SPARK_LICENSE_TAX_RATE', 0),
    'expiring_soon_days' => 30,
    'recommended_periodicity' => 'annual',

    'periodicities' => [
        'monthly' => ['label' => 'Mensuel', 'months' => 1, 'days' => 30],
        'quarterly' => ['label' => 'Trimestriel', 'months' => 3, 'days' => 90],
        'annual' => ['label' => 'Annuel', 'months' => 12, 'days' => 365],
    ],

    'plans' => [
        'growth' => [
            'label' => 'Growth',
            'limits' => ['agencies' => 3, 'users' => 40, 'storage_gb' => 2],
            'features' => ['Multi-agences', 'Rapports standards', 'Offline 3 jours'],
            'prices' => ['monthly' => 9000, 'quarterly' => 25000, 'annual' => 90000],
        ],
        'business' => [
            'label' => 'Business',
            'limits' => ['agencies' => 6, 'users' => 100, 'storage_gb' => 5],
            'features' => ['Multi-agences', 'Rapports avancés', 'Offline 7 jours', 'API', 'Support prioritaire'],
            'prices' => ['monthly' => 15000, 'quarterly' => 40000, 'annual' => 150000],
        ],
        'enterprise' => [
            'label' => 'Enterprise',
            'limits' => ['agencies' => 20, 'users' => 300, 'storage_gb' => 20],
            'features' => ['Multi-agences', 'Rapports avancés', 'Offline 30 jours', 'API', 'Support dédié', 'SLA'],
            'prices' => ['monthly' => 35000, 'quarterly' => 95000, 'annual' => 350000],
        ],
    ],

    'payment_methods' => [
        'cash' => 'Espèces',
        'card' => 'Carte',
        'flooz' => 'Flooz',
        'tmoney' => 'T-Money',
        'mobile_money' => 'Mobile Money',
    ],

    'proof' => [
        'mimes' => ['pdf', 'jpg', 'jpeg', 'png'],
        'max_kb' => 10240,
    ],
];
