<?php

return [
    'currency' => 'XAF',
    'api_prefix' => 'v1',
    'force_https' => (bool) env('SPARK_FORCE_HTTPS', false),

    /*
    | Decisions Phase 0 (CDC §18) — défauts en attendant l'atelier métier.
    | 1. Clients : globaux au pressing, agence d'origine enregistrée.
    | 2. Catalogue : partagé au pressing ; tarifs par agence (agency_prices).
    | 3. Retrait si impayé : bloqué, configurable par pressing.
    | 8. Montants : entiers en unité mineure ISO 4217 (Currency).
    | 9. Cycle atelier : laveur + classeur activés par défaut.
    | 10. Points fidélité : taux paramétrable par pressing (Phase 2).
    */
    'defaults' => [
        'pricing_mode' => 'piece',
        'workflow_laveur_enabled' => true,
        'workflow_classeur_enabled' => true,
        'block_retrieve_if_unpaid' => true,
        'loyalty_points_rate' => 0,
        'hours_classic' => 48,
        'hours_express' => 24,
        'hours_repass' => 12,
    ],

    'referral_bonus_xaf' => (int) env('SPARK_REFERRAL_BONUS', 0),
    'enforce_licenses' => (bool) env('SPARK_ENFORCE_LICENSES', false),
];
