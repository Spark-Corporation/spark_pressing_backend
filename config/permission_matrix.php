<?php

/*
| Matrice des droits (Figma « 11 — Rôles et permissions » / « Créer un rôle »).
| Chaque action est une permission Spatie fine (`domaine.action`). `grants` liste les permissions
| « techniques » déjà utilisées par les routes : elles sont ajoutées au rôle quand l’action est cochée.
| `sensitive` = droit journalisé et à confirmer à la création du rôle.
*/
return [
    'columns' => [
        'view' => 'Consultation',
        'create' => 'Création',
        'update' => 'Modification',
        'delete' => 'Suppression / annulation',
        'export' => 'Export',
    ],

    'domains' => [
        'clients' => [
            'label' => 'Clients', 'detail' => 'Fiches et historique client',
            'actions' => [
                'clients.view' => ['label' => 'Voir', 'column' => 'view', 'grants' => ['clients.manage']],
                'clients.create' => ['label' => 'Créer', 'column' => 'create', 'grants' => ['clients.manage']],
                'clients.update' => ['label' => 'Modifier', 'column' => 'update', 'grants' => ['clients.manage']],
                'clients.delete' => ['label' => 'Supprimer', 'column' => 'delete', 'sensitive' => true, 'grants' => ['clients.manage']],
                'clients.export' => ['label' => 'Exporter', 'column' => 'export', 'sensitive' => true, 'grants' => ['clients.manage']],
            ],
        ],
        'deposits' => [
            'label' => 'Dépôts', 'detail' => 'Tickets et commandes',
            'actions' => [
                'deposits.view' => ['label' => 'Voir', 'column' => 'view', 'grants' => ['deposits.view']],
                'deposits.create' => ['label' => 'Créer', 'column' => 'create', 'grants' => ['deposits.create']],
                'deposits.update' => ['label' => 'Modifier', 'column' => 'update', 'grants' => ['deposits.create']],
                'deposits.cancel' => ['label' => 'Annuler', 'column' => 'delete', 'sensitive' => true, 'grants' => ['deposits.create']],
                'deposits.export' => ['label' => 'Exporter', 'column' => 'export', 'grants' => ['deposits.view']],
            ],
        ],
        'payments' => [
            'label' => 'Paiements', 'detail' => 'Encaissements et remboursements',
            'actions' => [
                'payments.view' => ['label' => 'Voir', 'column' => 'view', 'grants' => ['deposits.view']],
                'payments.collect' => ['label' => 'Encaisser', 'column' => 'create', 'sensitive' => true, 'grants' => ['deposits.pay']],
                'payments.adjust' => ['label' => 'Ajuster', 'column' => 'update', 'sensitive' => true, 'grants' => ['deposits.pay']],
                'payments.refund' => ['label' => 'Rembourser', 'column' => 'delete', 'sensitive' => true, 'grants' => ['deposits.override']],
                'payments.export' => ['label' => 'Exporter', 'column' => 'export', 'sensitive' => true, 'grants' => ['reports.view']],
            ],
        ],
        'workshop' => [
            'label' => 'Atelier', 'detail' => 'Traitement et statuts',
            'actions' => [
                'workshop.view' => ['label' => 'Voir', 'column' => 'view', 'grants' => ['deposits.view']],
                'workshop.plan' => ['label' => 'Planifier', 'column' => 'create', 'grants' => ['workshop.transition']],
                'workshop.transition' => ['label' => 'Changer le statut', 'column' => 'update', 'grants' => ['workshop.transition']],
                'workshop.cancel' => ['label' => 'Annuler', 'column' => 'delete', 'grants' => ['workshop.transition']],
            ],
        ],
        'retrievals' => [
            'label' => 'Retraits', 'detail' => 'Remise et suivi des articles',
            'actions' => [
                'retrievals.view' => ['label' => 'Voir', 'column' => 'view', 'grants' => ['deposits.view']],
                'retrievals.handover' => ['label' => 'Remettre', 'column' => 'create', 'grants' => ['deposits.retrieve']],
                'retrievals.update' => ['label' => 'Modifier', 'column' => 'update', 'grants' => ['deposits.retrieve']],
                'retrievals.cancel' => ['label' => 'Annuler', 'column' => 'delete', 'sensitive' => true, 'grants' => ['deposits.override']],
            ],
        ],
        'cash' => [
            'label' => 'Caisse', 'detail' => 'Sessions et mouvements',
            'actions' => [
                'cash.view' => ['label' => 'Voir', 'column' => 'view', 'grants' => ['cash.manage']],
                'cash.open' => ['label' => 'Ouvrir', 'column' => 'create', 'grants' => ['cash.manage']],
                'cash.close' => ['label' => 'Clôturer', 'column' => 'update', 'sensitive' => true, 'grants' => ['cash.manage']],
                'cash.correct' => ['label' => 'Corriger', 'column' => 'delete', 'sensitive' => true, 'grants' => ['cash.approve']],
                'cash.export' => ['label' => 'Exporter', 'column' => 'export', 'sensitive' => true, 'grants' => ['reports.view']],
            ],
        ],
        'reports' => [
            'label' => 'Rapports', 'detail' => 'Indicateurs des agences affectées',
            'actions' => [
                'reports.view' => ['label' => 'Voir', 'column' => 'view', 'grants' => ['reports.view']],
                'reports.export' => ['label' => 'Exporter', 'column' => 'export', 'sensitive' => true, 'grants' => ['reports.view']],
            ],
        ],
        'settings' => [
            'label' => 'Paramètres', 'detail' => 'Catalogue, taxes et reçus',
            'actions' => [
                'settings.view' => ['label' => 'Voir', 'column' => 'view', 'grants' => []],
                'settings.update' => ['label' => 'Modifier', 'column' => 'update', 'sensitive' => true, 'grants' => ['settings.manage', 'articles.manage', 'prices.manage']],
            ],
        ],
        'agencies' => [
            'label' => 'Agences', 'detail' => 'Accès au périmètre autorisé',
            'actions' => [
                'agencies.view' => ['label' => 'Voir', 'column' => 'view', 'grants' => []],
                'agencies.create' => ['label' => 'Créer', 'column' => 'create', 'sensitive' => true, 'grants' => ['agencies.manage']],
                'agencies.update' => ['label' => 'Modifier', 'column' => 'update', 'sensitive' => true, 'grants' => ['agencies.manage']],
                'agencies.delete' => ['label' => 'Supprimer', 'column' => 'delete', 'sensitive' => true, 'grants' => ['agencies.manage']],
            ],
        ],
        'audit' => [
            'label' => 'Audit', 'detail' => 'Journal des actions et accès',
            'actions' => [
                'audit.view' => ['label' => 'Consulter', 'column' => 'view', 'sensitive' => true, 'grants' => ['audit.view']],
                'audit.export' => ['label' => 'Exporter', 'column' => 'export', 'sensitive' => true, 'grants' => ['audit.view']],
            ],
        ],
    ],

    /* Rôles système : non modifiables par « Créer un rôle ». */
    'system_roles' => [
        'admin' => ['label' => 'Admin', 'description' => 'Administration complète du pressing', 'scope' => 'all', 'data_level' => 'network', 'inheritance' => 'Accès total'],
        'manager' => ['label' => 'Manager', 'description' => 'Pilotage d’une ou plusieurs agences', 'scope' => 'assigned', 'data_level' => 'agency', 'inheritance' => 'Limité par Admin'],
        'caissier' => ['label' => 'Caissier', 'description' => 'Accueil, dépôts, encaissements et retraits', 'scope' => 'assigned', 'data_level' => 'agency', 'inheritance' => 'Limité par Manager'],
        'laveur' => ['label' => 'Laveur', 'description' => 'Traitement atelier', 'scope' => 'assigned', 'data_level' => 'agency', 'inheritance' => 'Limité par Manager'],
        'classeur' => ['label' => 'Classeur', 'description' => 'Contrôle, rangement et préparation des retraits', 'scope' => 'assigned', 'data_level' => 'agency', 'inheritance' => 'Limité par Manager'],
        'livreur' => ['label' => 'Livreur', 'description' => 'Collecte et livraison', 'scope' => 'assigned', 'data_level' => 'agency', 'inheritance' => 'Limité par Manager'],
    ],

    /* Droits fins des rôles système (colonne de la matrice). */
    'system_grants' => [
        'manager' => ['*', '!agencies.create', '!agencies.delete', '!audit.export'],
        'caissier' => [
            'clients.view', 'clients.create', 'clients.update', 'deposits.view', 'deposits.create', 'deposits.update',
            'payments.view', 'payments.collect', 'workshop.view', 'retrievals.view', 'retrievals.handover',
            'cash.view', 'cash.open', 'cash.close', 'reports.view',
        ],
        'laveur' => ['workshop.view', 'workshop.transition'],
        'classeur' => ['workshop.view', 'retrievals.view', 'retrievals.update'],
        'livreur' => ['deposits.view', 'payments.view', 'payments.collect', 'retrievals.view', 'retrievals.handover'],
    ],

    'data_levels' => [
        'agency' => 'Données de l’agence',
        'assigned_agencies' => 'Données des agences affectées',
        'network' => 'Données du réseau',
    ],
];
