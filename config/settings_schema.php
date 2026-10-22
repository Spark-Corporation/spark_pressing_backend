<?php

/*
| Paramètres du pressing (écrans Figma « 09 — Paramètres »).
| Chaque catégorie déclare ses valeurs par défaut (valeurs de la maquette) ; le type de la valeur
| par défaut sert de validation. `bound` relie une clé à une colonne existante de `pressings`
| (source de vérité utilisée par le reste de l’API). `versioned` active brouillon / publication.
*/
return [
    'categories' => [
        'operations' => [
            'label' => 'Paramètres opérationnels',
            'bound' => [
                'delays.classic_hours' => 'hours_classic',
                'delays.express_hours' => 'hours_express',
                'delays.repass_hours' => 'hours_repass',
                'retrieve.block_if_unpaid' => 'block_retrieve_if_unpaid',
                'workshop.washer_step' => 'workflow_laveur_enabled',
                'workshop.sorter_step' => 'workflow_classeur_enabled',
                'pricing.mode' => 'pricing_mode',
                'pricing.collection_fee' => 'collection_fee',
                'pricing.delivery_fee' => 'delivery_fee',
                'pricing.express_surcharge_percent' => 'express_surcharge_percent',
            ],
            'defaults' => [
                'delays' => ['classic_hours' => 48, 'express_hours' => 8, 'repass_hours' => 24],
                'retrieve' => ['allow_immediate' => true, 'block_if_unpaid' => true],
                'workshop' => ['washer_step' => true, 'sorter_step' => true],
                'pricing' => ['mode' => 'piece', 'kilo_price' => 1200, 'collection_fee' => 1500, 'delivery_fee' => 2000, 'express_surcharge_percent' => 100],
            ],
        ],
        'loyalty' => [
            'label' => 'Fidélité',
            'bound' => [
                'program.earn_points_per_1000' => 'loyalty_points_rate',
                'program.conversion_points' => 'loyalty_redeem_threshold',
                'program.conversion_value' => 'loyalty_redeem_value',
            ],
            'defaults' => [
                'program' => [
                    'active' => true,
                    'earn_points_per_1000' => 10,
                    'conversion_points' => 200,
                    'conversion_value' => 1000,
                    'minimum_points' => 500,
                    'expiration_months' => 12,
                    'credit_after_payment' => true,
                    'stack_with_promotions' => false,
                ],
                'tiers' => [
                    'basis' => 'points',
                    'levels' => [
                        ['code' => 'essentiel', 'label' => 'Essentiel', 'min' => 0, 'multiplier' => 1.0, 'benefit' => 'Aucun bonus'],
                        ['code' => 'argent', 'label' => 'Argent', 'min' => 1000, 'multiplier' => 1.1, 'benefit' => '+10 % de points'],
                        ['code' => 'or', 'label' => 'Or', 'min' => 2500, 'multiplier' => 1.25, 'benefit' => 'Priorité & retouches'],
                        ['code' => 'platine', 'label' => 'Platine', 'min' => 4000, 'multiplier' => 1.5, 'benefit' => 'Collecte offerte'],
                    ],
                ],
            ],
        ],
        'delivery' => [
            'label' => 'Collecte & livraison',
            'defaults' => [
                'minimum_order' => 5000,
                'collection_enabled' => true,
                'delivery_enabled' => true,
                'fees' => [
                    'calculation' => 'zone',
                    'collection_reference' => 1500,
                    'delivery_reference' => 2000,
                    'free_threshold' => 25000,
                    'free_delivery_enabled' => true,
                    'eligible_clients' => 'all',
                    'stack_with_promotions' => false,
                ],
                'schedule' => [
                    'days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'],
                    'opens_at' => '09:00',
                    'closes_at' => '17:00',
                    'slot_minutes' => 120,
                    'slot_capacity' => 8,
                    'booking_lead_hours' => 4,
                    'daily_cutoff' => '13:00',
                    'breaks' => [['from' => '13:00', 'to' => '15:00']],
                    'closed_dates' => [],
                ],
            ],
        ],
        'payments' => [
            'label' => 'Paiements',
            'bound' => [
                'cash_movements.control_threshold' => 'cash_double_control_threshold',
                'discounts.sensitive_percent' => 'sensitive_discount_percent',
            ],
            'defaults' => [
                'methods' => ['cash' => true, 'card' => true, 'mobile_money' => true, 'wallet' => true],
                'default_method' => 'cash',
                'advance' => ['type' => 'percent', 'value' => 30, 'allow_split' => true, 'max_installments' => 3, 'allow_multiple_methods' => true, 'balance_due' => 'before_retrieve'],
                'discounts' => ['max_percent' => 5, 'sensitive_percent' => 15, 'require_reason' => true],
                'refunds' => ['require_validation' => true],
                'receipt_per_payment' => true,
                'cash_movements' => ['control_threshold' => 250000, 'require_category' => true],
            ],
        ],
        'notifications' => [
            'label' => 'Notifications',
            'defaults' => [
                'events' => [
                    'deposit_created' => ['sms' => true, 'email' => true],
                    'in_progress' => ['sms' => true, 'email' => false],
                    'ready' => ['sms' => true, 'email' => true],
                    'late' => ['sms' => true, 'email' => true],
                    'retrieved' => ['sms' => false, 'email' => true],
                    'payment_received' => ['sms' => true, 'email' => true],
                    'loyalty' => ['sms' => false, 'email' => true],
                ],
                'templates' => [
                    'deposit_created' => ['name' => 'Dépôt enregistré — standard', 'message' => 'Bonjour {{client.prenom}}, votre dépôt {{depot.numero}} est enregistré à l’agence {{agence.nom}}. Retrait prévu le {{depot.date_limite}}.'],
                    'in_progress' => ['name' => 'En traitement — standard', 'message' => 'Bonjour {{client.prenom}}, votre dépôt {{depot.numero}} est en cours de traitement.'],
                    'ready' => ['name' => 'Prêt au retrait — standard', 'message' => 'Bonjour {{client.prenom}}, votre dépôt {{depot.numero}} est prêt à l’agence {{agence.nom}}. Reste à payer : {{depot.solde}} FCFA. Retrait avant le {{depot.date_limite}}.'],
                    'late' => ['name' => 'Retard — standard', 'message' => 'Bonjour {{client.prenom}}, votre dépôt {{depot.numero}} prendra un peu plus de temps. Nouveau délai : {{depot.date_limite}}. Toutes nos excuses.'],
                    'retrieved' => ['name' => 'Retrait effectué — standard', 'message' => 'Bonjour {{client.prenom}}, les articles du dépôt {{depot.numero}} vous ont été remis. Merci de votre confiance.'],
                    'payment_received' => ['name' => 'Paiement reçu — standard', 'message' => 'Bonjour {{client.prenom}}, nous avons reçu votre paiement pour le dépôt {{depot.numero}}. Reste à payer : {{depot.solde}} FCFA.'],
                    'loyalty' => ['name' => 'Fidélité — standard', 'message' => 'Bonjour {{client.prenom}}, vous avez désormais {{client.points}} points fidélité.'],
                ],
                'quiet_hours' => ['enabled' => true, 'start' => '20:00', 'end' => '08:00'],
                'compliance' => ['require_marketing_consent' => true, 'unsubscribe_link' => true, 'log_proofs' => true],
                'providers' => ['sms' => 'Orange Business CI · Production', 'email' => 'Brevo · contact@sparkpressing.ci'],
            ],
        ],
        'branding' => [
            'label' => 'Branding du pressing',
            'versioned' => true,
            'defaults' => [
                'name' => 'SPARK PRESSING',
                'logo_url' => null,
                'monogram' => 'SP',
                'primary_color' => '#24483F',
                'secondary_color' => '#C8A54B',
                'domain' => null,
                'phone' => null,
                'email' => null,
                'address' => null,
                'legal_mentions' => null,
                'default_document' => 'ticket',
                'ticket_footer' => 'Merci pour votre confiance.',
                'conditions' => 'Retrait sous 30 jours maximum.',
            ],
        ],
        'receipts' => [
            'label' => 'Configuration des reçus',
            'versioned' => true,
            'defaults' => [
                'default_model' => 'ticket',
                'paper' => '80mm',
                'copies' => 1,
                'layout' => ['alignment' => 'center', 'font_size' => 10, 'density' => 'compact', 'width_mm' => 80, 'margins_mm' => 4, 'line_height' => 1.2],
                'fields' => [
                    'pressing_agency' => true, 'contacts' => true, 'rccm_nif' => true, 'client_phone' => true, 'cashier' => true, 'dates' => true,
                    'treatment' => true, 'taxes' => false, 'discounts_loyalty' => true, 'advance' => true, 'left_to_pay' => true, 'pieces_count' => true,
                ],
                'block_order' => ['header', 'deposit', 'articles', 'totals'],
                'a4_columns' => ['quantity' => true, 'designation' => true, 'unit_price' => true, 'amount' => true, 'storage' => true, 'condition' => true, 'article_reference' => false, 'public_note' => false],
                'footer' => ['qr_code' => true, 'barcode' => true, 'signature' => true, 'contacts' => true, 'legal_mentions' => true, 'storage_policy' => true, 'message' => true],
                'footer_message' => 'Merci pour votre confiance. Conservez ce reçu.',
                'documents_by_event' => ['deposit' => 'ticket', 'payment' => 'payment_receipt', 'retrieve' => 'retrieve_slip', 'workshop' => 'workshop_label', 'proforma' => 'proforma', 'invoice' => 'invoice_a4'],
            ],
        ],
        'security' => [
            'label' => 'Sécurité',
            'defaults' => [
                'mfa' => ['enrollment_days' => 7, 'recovery' => 'backup_codes_admin', 'required_roles' => ['admin', 'manager']],
                'passwords' => ['min_length' => 12, 'history' => 5, 'refuse_compromised' => true, 'periodic_renewal' => false],
                'sessions' => ['max_hours' => 8, 'inactivity_lock_minutes' => 15, 'reauth_sensitive_actions' => true],
                'lockout' => ['max_attempts' => 5, 'lock_minutes' => 15],
                'devices' => ['require_approved' => true, 'validity_days' => 90],
                'permissions' => [
                    'modify_policies' => 'admin',
                    'validate_exceptions' => 'manager+admin',
                    'export_client_data' => 'admin',
                    'revoke_device' => 'admin+mfa',
                ],
            ],
        ],
        'offline' => [
            'label' => 'Mode hors ligne',
            'defaults' => [
                'enabled' => true,
                'scope' => 'cash',
                'sync_interval_minutes' => 5,
                'local_retention_hours' => 72,
                'auto_resolve' => false,
                'cache' => [
                    'recent_clients_days' => 30,
                    'recent_clients_max' => 500,
                    'max_offline_hours' => 72,
                    'max_queue' => 200,
                    'cache_size_mb' => 50,
                    'refresh_minutes' => 5,
                    'auto_refresh' => true,
                    'exclude_sensitive' => true,
                ],
            ],
        ],
        'audit' => [
            'label' => 'Audit & conservation',
            'defaults' => [
                'scope' => 'all',
                'agency_ids' => [],
                'categories' => ['deposits' => true, 'payments' => true, 'cancellations' => true, 'discounts' => true, 'retrievals' => true, 'cash' => true, 'security' => true, 'settings' => true, 'exports' => true],
                'retention_months' => 24,
                'archive_after_months' => 12,
                'export_format' => 'csv_anonymized',
            ],
        ],
    ],

    /* Cartes du hub « Paramètres » : groupe, page cible. */
    'hub' => [
        'structure' => ['organisation', 'users', 'catalog', 'deposit_codes'],
        'operations' => ['delays', 'promotions', 'loyalty', 'workshop_cycle'],
        'finance' => ['pricing', 'delivery', 'payments', 'notifications'],
        'platform' => ['branding', 'security', 'offline', 'audit'],
    ],
];
