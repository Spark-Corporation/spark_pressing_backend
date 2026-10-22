<?php

namespace App\Support\Scribe\Strategies;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\Strategy;

/**
 * Injecte des exemples de réponses {data, meta, errors} pour tous les endpoints API
 * qui n'ont pas encore d'exemples (ex. via #[Response]).
 */
class DocumentApiEnvelopeResponses extends Strategy
{
    public function __invoke(ExtractedEndpointData $endpointData, array $settings = []): ?array
    {
        if (! empty($endpointData->responses) && count($endpointData->responses) > 0) {
            return null;
        }

        $method = strtoupper($endpointData->httpMethods[0] ?? 'GET');
        $path = '/'.ltrim($endpointData->uri ?? '', '/');
        $path = preg_replace('#\{[^}]+\}#', '{id}', $path) ?? $path;

        $responses = $this->examplesFor($method, $path);

        if ($responses === []) {
            $responses = [$this->fallback($method)];
        }

        // Erreurs communes (sauf health / login déjà couverts ailleurs).
        if (! str_contains($path, '/health')) {
            $responses[] = $this->response(
                401,
                null,
                ['message' => 'Non authentifié.'],
                [['code' => 'unauthenticated', 'detail' => 'Non authentifié.']],
                'Non authentifié'
            );
        }

        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $responses[] = $this->response(
                422,
                null,
                ['message' => 'Les données sont invalides.'],
                [['code' => 'validation', 'field' => 'field', 'detail' => 'Le champ est invalide.']],
                'Validation'
            );
        }

        return $responses;
    }

    /** @return list<array{status:int,description:string,content:string}> */
    private function examplesFor(string $method, string $path): array
    {
        $agency = [
            'id' => 1,
            'pressing_id' => 1,
            'name' => 'Akwa',
            'address' => 'Douala, Akwa',
            'contact' => '+237600000001',
            'country_code' => 'CM',
            'currency' => 'XAF',
            'code_prefix' => 'AK',
            'code_suffix' => 'EL',
            'status' => true,
        ];

        $pressing = [
            'id' => 1,
            'name' => 'Élégance Pressing',
            'details' => 'Enseigne de démonstration',
            'status' => true,
            'pricing_mode' => 'piece',
            'workflow_laveur_enabled' => true,
            'workflow_classeur_enabled' => true,
            'qr_labels_enabled' => false,
            'block_retrieve_if_unpaid' => true,
            'reporting_currency' => 'XAF',
            'collection_fee' => 0,
            'delivery_fee' => 0,
            'loyalty_points_rate' => 1,
            'hours_classic' => 48,
            'hours_express' => 24,
            'hours_repass' => 12,
        ];

        $client = [
            'id' => 1,
            'pressing_id' => 1,
            'agency_id' => 1,
            'code' => 'CLI-00001',
            'fullname' => 'Jean Client',
            'email' => 'jean.client@example.com',
            'phone_number' => '690000001',
            'address' => null,
            'city' => 'Douala',
            'loyalty_points' => 120,
            'wallet_balance' => 5000,
            'sponsor_code' => 'JEAN01',
            'referred_by_id' => null,
            'last_deposit_at' => '2026-10-14T10:00:00+00:00',
            'status' => true,
            'first_name' => 'Jean',
            'last_name' => 'Client',
            'phone_secondary' => null,
            'contact_preference' => 'whatsapp',
            'consent_sms' => true,
            'consent_email' => false,
            'archived_at' => null,
            'tier' => ['code' => 'essentiel', 'label' => 'Essentiel'],
        ];
        $clientRow = $client + [
            'agency' => ['id' => 1, 'name' => 'Akwa'],
            'deposits_count' => 42,
            'initials' => 'JC',
            'frequency_per_month' => 2.8,
            'lifetime_value' => 486500,
            'crm_status' => ['code' => 'active', 'label' => 'Actif'],
        ];
        $clientsMeta = [
            'kpis' => [
                'active_clients' => ['value' => 2846, 'delta_percent_month' => 8.2],
                'new_clients' => ['value' => 184, 'this_week' => 24],
                'vip_clients' => ['value' => 126, 'share_percent' => 4.4],
                'points' => ['value' => 482000, 'earned_this_month' => 52000, 'delta_percent_month' => 12.5],
            ],
            'loyalty_distribution' => ['total' => 2846, 'tiers' => [
                ['code' => 'essentiel', 'label' => 'Essentiel', 'count' => 1624, 'percent' => 57],
                ['code' => 'argent', 'label' => 'Argent', 'count' => 738, 'percent' => 26],
                ['code' => 'or', 'label' => 'Or', 'count' => 358, 'percent' => 13],
                ['code' => 'platine', 'label' => 'Platine', 'count' => 126, 'percent' => 4],
            ]],
            'tier_filters' => [
                ['code' => null, 'label' => 'Tous', 'count' => 2846],
                ['code' => 'essentiel', 'label' => 'Essentiel', 'count' => 1624],
            ],
            'synced_at' => '2026-10-14T10:38:00+00:00',
        ];
        $clientProfile = array_merge($clientRow, [
            'contact_preference' => ['code' => 'whatsapp', 'label' => 'WhatsApp'],
            'stats' => ['lifetime_value' => 486500, 'client_since' => '2024-03-18', 'deposits' => 42, 'frequency_per_month' => 2.8, 'average_basket' => 24325, 'average_basket_delta_percent_6m' => 6.4, 'balance_due' => 12500],
            'loyalty' => [
                'points' => 3240, 'value' => 16200,
                'tier' => ['code' => 'or', 'label' => 'Or'],
                'next_tier' => ['code' => 'platine', 'label' => 'Platine', 'min_points' => 4000],
                'points_to_next_tier' => 760, 'progress_percent' => 81,
                'conversion' => ['points' => 100, 'value' => 500, 'minimum_points' => 500],
            ],
            'points_history' => [['label' => 'Dépôt', 'reason' => 'Paiement dépôt DEP-240928-041', 'reference' => 'DEP-240928-041', 'points' => 325, 'at' => '2026-09-28T09:42:00+00:00']],
            'timeline' => [['type' => 'retrieve', 'title' => 'Retrait effectué', 'detail' => '3 articles remis', 'reference' => 'CMD-240928-018', 'at' => '2026-10-14T10:16:00+00:00']],
            'recent_orders' => [['id' => 1, 'code' => 'CMD-240928-018', 'date' => '2026-09-28T09:42:00+00:00', 'articles' => 5, 'total' => 32500, 'status' => ['code' => 'ready', 'label' => 'Prête au retrait']]],
            'preferences' => ['contact_channel' => 'WhatsApp', 'pickup_slot' => 'Fin de journée · 17h–19h', 'favorite_treatment' => 'Soin premium sans parfum', 'instructions' => 'Chemises sur cintre, plis légers'],
            'notes' => ['count' => 2, 'items' => [['id' => 1, 'body' => 'Cliente attentive aux finitions.', 'author' => 'Fatou', 'created_at' => '2026-09-28T10:00:00+00:00']]],
            'account' => ['client_since' => '2024-03-18', 'agency' => 'Akwa', 'marketing_consent' => true, 'consents' => ['sms' => true, 'email' => false, 'updated_at' => '2026-03-18T10:00:00+00:00'], 'archived_at' => null],
        ]);

        $article = [
            'id' => 1,
            'name' => 'Chemise',
            'description' => null,
            'classic_price' => 1000,
            'express_price' => 1500,
            'repass_price' => 700,
            'classic_price_kilo' => null,
            'express_price_kilo' => null,
            'repass_price_kilo' => null,
            'status' => true,
            'code' => 'CHE-001',
            'icon' => 'shirt',
            'category_id' => 1,
            'category' => ['id' => 1, 'name' => 'Hauts'],
            'billing_mode' => 'piece',
            'allow_discount' => true,
            'round_to' => 100,
            'price_editable' => false,
            'delays' => ['classic' => 72, 'express' => 24, 'repass' => 12],
            'priority' => 'standard',
            'accepted_defects' => ['stain', 'torn', 'broken_button'],
            'compatible_renders' => [1, 2],
            'services' => ['collection', 'delivery'],
            'price_updated_at' => '2026-09-18T16:24:00+00:00',
        ];
        $articleRow = $article + [
            'billing_mode_label' => 'Pièce',
            'scope' => ['status' => ['code' => 'active', 'label' => 'Actif'], 'label' => 'Partagé', 'agencies' => 4],
        ];
        $articleDetail = $articleRow + [
            'kilo_tiers' => [['min_kg' => 0, 'max_kg' => 3, 'classic' => 2800, 'express' => 4100]],
            'availability' => ['active' => 3, 'total' => 4, 'agencies' => [
                ['agency_id' => 1, 'agency' => 'Cocody Angré', 'available' => true, 'status' => ['code' => 'available', 'label' => 'Disponible'], 'price_adjust_percent' => 0, 'note' => 'Catalogue principal'],
                ['agency_id' => 2, 'agency' => 'Plateau Centre', 'available' => true, 'status' => ['code' => 'available', 'label' => 'Disponible'], 'price_adjust_percent' => 5, 'note' => 'Prix local +5%'],
            ]],
            'preview' => ['title' => 'Chemise', 'subtitle' => 'CHE-001 · Délai 72 h', 'badge' => 'Hauts · Pièce', 'prices' => [['label' => 'Classique', 'amount' => 1000], ['label' => 'Express', 'amount' => 1500], ['label' => 'Repassage', 'amount' => 700]]],
            'checks' => [['code' => 'required', 'label' => 'Informations obligatoires', 'ok' => true], ['code' => 'prices', 'label' => 'Tarifs cohérents', 'ok' => true]],
            'recommendations' => [['code' => 'express_gap', 'label' => 'Le tarif Express est supérieur de 46%. Vérifiez le catalogue partagé.']],
            'last_modification' => ['by' => 'Nadia Assi', 'at' => '2026-09-18T16:24:00+00:00'],
            'options' => ['defects' => [['code' => 'stain', 'label' => 'Taché']], 'renders' => [['id' => 1, 'title' => 'Sur cintre']], 'services' => [['code' => 'collection', 'label' => 'Collecte']], 'priorities' => [['code' => 'standard', 'label' => 'Standard']]],
        ];

        $deposit = [
            'id' => 1,
            'client_uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'code' => 'AK-0001-EL',
            'qr_token' => 'qr-demo-token',
            'pressing_id' => 1,
            'agency_id' => 1,
            'currency' => 'XAF',
            'client_id' => 1,
            'deposit_date' => '2026-10-14T09:00:00+00:00',
            'retrieve_date' => '2026-10-16T09:00:00+00:00',
            'retrieved_at' => null,
            'subtotal' => 3500,
            'collection_fee' => 0,
            'delivery_fee' => 0,
            'discount' => 0,
            'discount_percent' => 0,
            'promo_code' => null,
            'points_earned' => 35,
            'points_redeemed' => 0,
            'total' => 3500,
            'advanced' => 1000,
            'left_to_pay' => 2500,
            'payment_method' => 'cash',
            'status' => 'open',
            'etat' => 'waiting',
            'etat_label' => 'À trier',
            'colonne' => 'attente',
            'workshop_status' => ['code' => 'waiting', 'label' => 'À trier'],
            'payment_status' => ['code' => 'partial', 'label' => 'Partiellement payé'],
            'service' => ['code' => 'express', 'label' => 'Express'],
            'is_express' => true,
            'express_supplement' => 1000,
            'pieces_count' => 2,
            'weight_kg_total' => 0,
            'quantity_label' => '2 pièces',
            'terminal' => 'POS-01',
            'local_ref' => null,
            'storage_location' => null,
            'discount_reason' => null,
            'sync_status' => ['code' => 'synced', 'label' => 'À jour', 'synced_at' => '2026-10-14T09:05:00+00:00'],
            'delivery_status' => null,
            'notes' => null,
            'items_count' => 2,
            'articles_label' => '2 pièces',
            'client_name' => 'Jean Client',
            'client_phone' => '690000001',
            'agency' => ['id' => 1, 'name' => 'Akwa'],
            'client' => $client,
            'units' => [
                [
                    'id' => 1,
                    'article_id' => 1,
                    'service_id' => 1,
                    'designation' => 'Chemise',
                    'pricing_type' => 'piece',
                    'quantity' => 2,
                    'unit_price' => 1000,
                    'line_total' => 2000,
                    'state' => null,
                    'is_express' => true,
                    'express_supplement' => 1000,
                    'color' => 'Blanches',
                    'defects' => [['code' => 'stain', 'label' => 'Taché']],
                    'internal_notes' => 'Tache au col',
                ],
            ],
            'transactions' => [
                [
                    'id' => 1,
                    'amount' => 1000,
                    'type' => 'in',
                    'payment_method' => 'cash',
                    'transaction_date' => '2026-10-14T09:05:00+00:00',
                ],
            ],
        ];

        $user = [
            'id' => 1,
            'fullname' => 'Claire Caissier',
            'email' => 'caissier@elegance.local',
            'phone_number' => null,
            'pressing_id' => 1,
            'agency_id' => 1,
            'status' => true,
            'roles' => ['caissier'],
        ];

        $cashMovement = [
            'id' => 1,
            'pressing_id' => 1,
            'agency_id' => 1,
            'client_uuid' => '550e8400-e29b-41d4-a716-446655440001',
            'label' => 'Achat fournitures',
            'type' => 'out',
            'amount' => 5000,
            'category_id' => 1,
            'validated' => true,
            'action_date' => '2026-10-14',
        ];

        $service = [
            'id' => 1,
            'pressing_id' => 1,
            'name' => 'Classique',
            'code' => 'classic',
            'legacy_type_action' => 0,
            'sort_order' => 1,
            'default_hours' => 48,
            'status' => true,
        ];

        $pageMeta = ['page' => 1, 'per_page' => 50, 'total' => 1, 'last_page' => 1];

        // —— Health ——
        if ($path === '/api/v1/health') {
            return [$this->response(200, [
                'name' => 'Spark Pressing API',
                'version' => 'v1',
                'status' => 'ok',
                'database' => 'ok',
                'currency' => 'XAF',
                'locale' => 'fr',
            ], description: 'API et DB OK')];
        }

        // —— POS / Dashboard ——
        if ($path === '/api/v1/dashboard') {
            return [$this->response(200, [
                'currency' => 'XAF',
                'generated_at' => '2026-10-20T16:00:00+00:00',
                'kpis' => [
                    'revenue_today' => 1845500,
                    'revenue_delta_pct' => 12.4,
                    'deposits_today' => 126,
                    'pieces_today' => 418,
                    'retrieves_today' => 94,
                    'retrieve_rate_pct' => 74.6,
                    'ready_to_notify' => 9,
                    'loyalty_clients' => 312,
                    'loyalty_clients_delta_pct' => 5.1,
                    'new_clients_month' => 23,
                    'clients' => 400,
                    'deposits_open' => 98,
                    'unpaid_orders' => 94,
                    'overdue_retrieves' => 18,
                ],
                'clients' => 400,
                'deposits_today' => 126,
                'retrieves_today' => 94,
                'receipts_today' => 1845500,
                'cash_balance_today' => 1745500,
                'series_7d' => [
                    ['date' => '2026-10-14', 'revenue' => 1200000, 'deposits' => 80],
                ],
                'agencies' => [[
                    'agency_id' => 1,
                    'name' => 'Akwa',
                    'revenue_week' => 5820000,
                    'revenue_week_delta_pct' => 14.2,
                    'revenue_week_share_pct' => 31.8,
                    'revenue' => 983500,
                    'revenue_delta_pct' => 8.2,
                    'deposits' => 42,
                    'retrieves' => 38,
                    'unpaid_count' => 5,
                    'unpaid_amount' => 45000,
                    'cash_balance' => 900000,
                ]],
                'week' => ['from' => '2026-10-14', 'to' => '2026-10-20', 'revenue' => 18291000, 'revenue_delta_pct' => 10.8],
                'workshop_pipeline' => [
                    'due_soon_count' => 6,
                    'in_progress_deposits' => 98,
                    'in_progress_pieces' => 320,
                    'columns' => [
                        ['etat' => 'waiting', 'colonne' => 'attente', 'label' => 'À trier', 'title' => 'En attente', 'deposits' => 14, 'pieces' => 46],
                    ],
                    'due_soon' => [['id' => 1, 'code' => 'BG-02614', 'etat' => 'in_progress', 'etat_label' => 'En lavage', 'retrieve_date' => '2026-10-20T17:00:00+00:00']],
                ],
                'cash' => [
                    'agency' => 'Bingerville',
                    'period' => 'today',
                    'status' => ['code' => 'open', 'label' => 'Caisse ouverte'],
                    'inflows' => 1945500,
                    'outflows' => 100000,
                    'balance' => 1745500,
                    'cash_in' => 0,
                    'cash_out' => 100000,
                    'receipts_deposits' => 1845500,
                    'by_payment_method' => [
                        'cash' => 742500,
                        'mobile_money' => 935000,
                        'card' => 168000,
                        'wallet' => 0,
                        'other' => 0,
                    ],
                ],
                'unpaid_aging' => [
                    'total_amount' => 1240000,
                    'total_count' => 94,
                    'overdue_count' => 94,
                    'due_today_count' => 18,
                    'buckets' => [
                        '0_30' => ['count' => 32, 'amount' => 420000],
                        '31_89' => ['count' => 41, 'amount' => 540000],
                        '90_plus' => ['count' => 21, 'amount' => 280000],
                    ],
                ],
                'recent_activity' => [
                    ['id' => 10, 'type' => 'deposit.created', 'agency_id' => 1, 'subject_type' => 'Deposit', 'subject_id' => 2621, 'at' => '2026-10-20T15:57:00+00:00', 'kind' => 'deposit', 'title' => 'Dépôt enregistré · BG-02621', 'subtitle' => 'Mariam Traoré · 7 pièces · Express', 'amount' => 32500, 'amount_type' => 'credit'],
                    ['id' => 9, 'type' => 'deposit.workshop', 'agency_id' => 1, 'subject_type' => 'Deposit', 'subject_id' => 2602, 'at' => '2026-10-20T15:42:00+00:00', 'kind' => 'workshop', 'title' => 'Commande passée à l’étape « Prêt »', 'subtitle' => 'BG-02602 · 4 pièces · Opérateur : Grâce', 'amount' => null, 'amount_type' => 'status', 'status_label' => 'Prêt'],
                    ['id' => 8, 'type' => 'cash.validated', 'agency_id' => 1, 'subject_type' => 'CashMovement', 'subject_id' => 12, 'at' => '2026-10-20T15:33:00+00:00', 'kind' => 'cash', 'title' => 'Dépense de caisse validée', 'subtitle' => 'Produits détachants', 'amount' => 45000, 'amount_type' => 'debit'],
                ],
                'sync' => [
                    'online' => true,
                    'last_sync_at' => '2026-10-20T16:00:00+00:00',
                    'pending_ops' => 0,
                    'pending' => [],
                ],
                'scope' => [
                    'pressing_id' => 1,
                    'agency_id' => 1,
                    'network_wide' => true,
                ],
            ])];
        }

        if ($path === '/api/v1/pos/bootstrap') {
            return [$this->response(200, [
                'generated_at' => '2026-10-20T16:00:00+00:00',
                'user' => $user,
                'agency' => $agency,
                'agency_id' => 1,
                'agencies' => [$agency],
                'permissions' => ['deposits.view', 'deposits.create'],
                'pressing' => $pressing,
                'articles' => [$article],
                'services' => [$service],
                'clients' => [$client],
                'promos' => [],
                'promo_specials' => [],
                'delivery_hours' => null,
                'code_suffixes' => [],
                'laundry_statuses' => [],
                'renders' => [],
                'deposit_options' => [
                    'express' => ['surcharge_percent' => 100, 'multiplier' => 2, 'hours' => 24, 'label' => 'Tarif ×2 · Délai express 24 h'],
                    'delays' => [['code' => 'standard', 'label' => 'Standard · 72 h', 'hours' => 72]],
                    'defects' => [['code' => 'stain', 'label' => 'Taché'], ['code' => 'torn', 'label' => 'Déchiré']],
                    'payment_methods' => [['code' => 'cash', 'label' => 'Espèces'], ['code' => 'card', 'label' => 'Carte'], ['code' => 'mobile_money', 'label' => 'Mobile Money']],
                    'discount_modes' => ['amount', 'percent'],
                    'pricing_modes' => ['piece', 'kilo'],
                ],
                'sync' => [
                    'online' => true,
                    'last_sync_at' => '2026-10-20T16:00:00+00:00',
                    'pending_ops' => 0,
                    'pending' => [],
                ],
            ])];
        }

        if ($path === '/api/v1/pos/sync/status') {
            return [$this->response(200, [
                'online' => true,
                'last_sync_at' => '2026-10-20T16:00:00+00:00',
                'pending_ops' => 0,
                'pending' => [],
            ])];
        }

        if ($path === '/api/v1/sync') {
            return [$this->response(200, [
                'operations' => [
                    ['type' => 'deposit', 'client_uuid' => '550e8400-e29b-41d4-a716-446655440000', 'id' => 1, 'code' => 'AK-0001', 'replayed' => false],
                ],
            ])];
        }

        $workshopCard = $deposit + [
            'priority' => ['code' => 'urgent', 'label' => 'Urgent'],
            'priorite' => 'Urgent',
            'echeance' => '2026-10-20T11:30:00+00:00',
            'overdue_minutes' => 32,
            'stage_since' => '2026-10-20T09:42:00+00:00',
            'stage_minutes' => 126,
            'elapsed_seconds' => 9000,
            'defects_count' => 2,
            'responsable' => ['id' => 4, 'fullname' => 'Jean Koffi', 'initials' => 'JK'],
        ];
        if ($path === '/api/v1/deposits/workshop') {
            return [$this->response(200, [
                'capacity' => ['in_progress' => 18, 'capacity' => 24, 'percent' => 75, 'label' => 'Charge modérée'],
                'workflow' => ['laveur_enabled' => true, 'classeur_enabled' => true],
                'columns' => [
                    ['etat' => 'in_progress', 'colonne' => 'cours', 'label' => 'En lavage', 'title' => 'En cours', 'count' => 6, 'share_percent' => 33, 'pieces' => 52, 'overdue_count' => 1, 'next_due_at' => '2026-10-20T11:30:00+00:00', 'average_stage_minutes' => 95],
                ],
                'board' => [
                    'attente' => [],
                    'cours' => [$workshopCard],
                    'traites' => [],
                    'classes' => [],
                ],
                'filters' => [
                    'services' => [['code' => 'express', 'label' => 'Express']],
                    'responsables' => [['id' => 4, 'fullname' => 'Jean Koffi']],
                ],
            ])];
        }
        if ($method === 'GET' && str_starts_with($path, '/api/v1/deposits/{') && str_ends_with($path, '/workshop')) {
            return [$this->response(200, $workshopCard + [
                'articles' => [['quantity' => 3, 'designation' => 'Chemise blanche', 'note' => 'Col fragile']],
                'remarks' => 'Tache de fond de teint au col.',
                'responsables' => ['laveur' => ['id' => 4, 'fullname' => 'Jean Koffi'], 'classeur' => null],
                'timeline' => [['at' => '2026-10-20T10:58:00+00:00', 'code' => 'in_progress', 'title' => 'Pris en charge', 'detail' => 'Validé par Jean Koffi']],
                'next_action' => ['etat' => 'treated', 'colonne' => 'traites', 'label' => 'Passer à « Traités »'],
            ])];
        }

        if ($path === '/api/v1/org/agencies/overview') {
            return [$this->response(200, [
                'generated_at' => '2026-10-20T16:00:00+00:00',
                'active_count' => 2,
                'agencies' => [[
                    'id' => 1,
                    'name' => 'Akwa',
                    'slug' => 'akwa',
                    'revenue_today' => 983500,
                    'deposits_today' => 42,
                    'retrieves_today' => 38,
                    'unpaid_amount' => 45000,
                    'status' => true,
                ]],
            ])];
        }

        // —— Caisse (Figma 06) ——
        $cashSession = ['id' => 1, 'code' => 'CAI-BG-0928', 'business_date' => '2026-09-28', 'status' => ['code' => 'open', 'label' => 'Caisse ouverte'], 'opened_at' => '2026-09-28T08:02:00+00:00', 'opened_by' => 'Grâce A.', 'opening_balance' => 180000, 'closed_at' => null];
        $cashClosing = [
            'session' => $cashSession,
            'status' => ['code' => 'ready', 'label' => 'Prête à clôturer'],
            'kpis' => ['receipts' => ['value' => 895500, 'count' => 51], 'expenses' => ['value' => 92000, 'count' => 4], 'theoretical' => ['value' => 983500], 'difference' => ['value' => 0]],
            'summary' => ['from' => '2026-09-28T08:02:00+00:00', 'opening' => 180000, 'deposit_receipts' => ['amount' => 612500, 'count' => 31], 'balance_payments' => ['amount' => 248000, 'count' => 18], 'cash_in' => ['amount' => 35000, 'count' => 2], 'cash_out' => ['amount' => 92000, 'count' => 4], 'theoretical' => 983500],
            'reconciliation' => [['code' => 'cash', 'label' => 'Espèces', 'theoretical' => 508000, 'counted' => 508000, 'difference' => 0, 'status' => ['code' => 'compliant', 'label' => 'Conforme']]],
            'result' => ['compliant' => true, 'label' => 'Caisse conforme · écart zéro', 'theoretical' => 983500, 'counted' => 983500, 'difference' => 0, 'reason' => null, 'counted_by' => 'Grâce Adjoua', 'counted_at' => '2026-09-28T17:56:00+00:00', 'controlled_by' => 'Aïcha Koné', 'controlled_at' => '2026-09-28T17:58:00+00:00'],
            'checklist' => ['total' => 6, 'done' => 6, 'items' => [['code' => 'journal', 'label' => 'Journal vérifié', 'detail' => '57 opérations contrôlées', 'done' => true]]],
            'operators' => [['id' => 1, 'fullname' => 'Grâce Adjoua', 'roles' => ['Ouverture'], 'operations' => 29]],
            'anomalies' => ['unsynced_operations' => 0, 'unresolved' => 0, 'pending_approvals' => 0],
            'report' => ['filename' => 'Cloture_BG_2026-09-28.pdf', 'url' => 'https://api.example.com/api/v1/cash/sessions/1/report'],
        ];
        $journalRow = ['id' => 'mvt-418', 'source' => 'movement', 'source_id' => 418, 'at' => '2026-09-28T15:42:00+00:00', 'reference' => 'MVT-00418', 'category' => 'Fournitures', 'label' => 'Produits détachants', 'operator' => 'Aïcha K.', 'payment_method' => ['code' => 'cash', 'label' => 'Espèces'], 'flow' => ['code' => 'out', 'label' => 'Sortie'], 'amount' => 45000, 'status' => ['code' => 'validated', 'label' => 'Validé']];
        if ($path === '/api/v1/cash/center') {
            return [$this->response(200, [
                'date' => '2026-09-28',
                'agency' => 'Bingerville',
                'session' => $cashSession,
                'kpis' => [
                    'receipts' => ['value' => 895500, 'delta_percent' => 12.4],
                    'expenses' => ['value' => 92000, 'count' => 4],
                    'unpaid' => ['value' => 186750, 'deposits' => 14],
                    'difference' => ['value' => 0, 'label' => 'Comptage conforme'],
                ],
                'situation' => ['opening' => ['amount' => 180000, 'at' => '2026-09-28T08:02:00+00:00', 'by' => 'Grâce A.'], 'deposit_receipts' => ['amount' => 612500, 'count' => 31], 'balance_payments' => ['amount' => 248000, 'count' => 18], 'cash_in' => ['amount' => 35000, 'count' => 2], 'cash_out' => ['amount' => 92000, 'count' => 4]],
                'theoretical' => ['amount' => 983500, 'computed_at' => '2026-09-28T16:42:00+00:00'],
                'counted' => ['amount' => 983500, 'difference' => 0],
                'breakdown' => ['total' => 895500, 'methods' => [['code' => 'cash', 'label' => 'Espèces', 'amount' => 420000, 'percent' => 46.9]]],
                'hourly' => ['series' => [['hour' => '07h', 'in' => 85000, 'out' => 0]], 'net' => 803500],
                'journal_totals' => ['operations' => 57, 'total_in' => 895500, 'total_out' => 92000],
            ])];
        }
        if ($path === '/api/v1/cash/journal') {
            return [$this->response(200, [$journalRow], array_merge($pageMeta, ['totals' => ['operations' => 57, 'total_in' => 895500, 'total_out' => 92000]]))];
        }
        if ($path === '/api/v1/cash/sessions/current') {
            return [$this->response(200, ['session' => $cashSession, 'available_cash' => 508000, 'double_control_threshold' => 250000])];
        }
        if ($path === '/api/v1/cash/sessions' && $method === 'POST') {
            return [$this->response(201, $cashSession)];
        }
        if (str_starts_with($path, '/api/v1/cash/sessions/{') && str_ends_with($path, '/report')) {
            return [[
                'status' => 200,
                'description' => 'Rapport de clôture (application/pdf)',
                'content' => json_encode(['data' => ['content_type' => 'application/pdf'], 'meta' => (object) [], 'errors' => null], JSON_UNESCAPED_UNICODE),
            ]];
        }
        if (str_starts_with($path, '/api/v1/cash/sessions/{')) {
            return [$this->response(200, $cashClosing)];
        }
        if (str_starts_with($path, '/api/v1/cash-movements/{') && $method !== 'DELETE') {
            return [$this->response(200, [
                'id' => 419, 'client_uuid' => '550e8400-e29b-41d4-a716-446655440001', 'label' => 'Achat urgent de produits', 'type' => 'out', 'amount' => 350000,
                'action_date' => '2026-09-28T16:44:00+00:00', 'validated' => false, 'category_id' => 2, 'category' => ['id' => 2, 'name' => 'Fournitures'],
                'reference_code' => 'MVT-00419', 'payment_method' => 'cash', 'beneficiary' => 'CleanPro Côte d’Ivoire', 'reference' => 'BC-2026-0928-18',
                'comment' => 'Commande exceptionnelle', 'has_proof' => true, 'requires_approval' => true, 'approved_at' => null,
                'status' => ['code' => 'pending_approval', 'label' => 'En attente'], 'operator' => 'Aïcha Koné',
                'double_control' => ['required' => true, 'initiator' => ['fullname' => 'Aïcha Koné', 'state' => 'Signé'], 'approver' => ['fullname' => null, 'state' => 'À notifier']],
                'traceability' => [
                    ['at' => '2026-09-28T16:44:00+00:00', 'title' => 'Brouillon créé', 'detail' => 'Aïcha Koné', 'done' => true],
                    ['at' => '2026-09-28T16:44:00+00:00', 'title' => 'Montant sensible détecté', 'detail' => 'Double contrôle requis au-delà du seuil du pressing', 'done' => true],
                    ['at' => null, 'title' => 'Seconde validation', 'detail' => 'En attente d’un manager habilité', 'done' => false],
                ],
                'cash_session' => ['id' => 1, 'code' => 'CAI-BG-0928', 'status' => 'open', 'available_cash' => 983500],
            ])];
        }

        // —— Retraits (Figma 05) ——
        $notification = ['code' => 'sent', 'label' => 'SMS envoyé', 'channel' => 'sms', 'at' => '2026-10-20T09:42:00+00:00'];
        if ($path === '/api/v1/retrievals/center') {
            return [$this->response(200, [$deposit + ['notification' => $notification]], array_merge($pageMeta, [
                'kpis' => [
                    'ready_today' => ['value' => 18, 'pieces' => 47],
                    'awaiting_notification' => ['value' => 5, 'by_channel' => ['sms' => 3, 'whatsapp' => 2]],
                    'retrieved_today' => ['value' => 27, 'delta_percent' => 12.0, 'compared_to' => '2026-10-13'],
                    'unpaid_balances' => ['value' => 86500, 'deposits' => 7],
                ],
                'today_schedule' => [
                    ['id' => 1, 'code' => 'BG-02641', 'time' => '2026-10-20T10:30:00+00:00', 'client' => 'Aminata Diarra', 'pieces' => 7, 'payment' => ['code' => 'to_collect', 'label' => 'À encaisser']],
                ],
            ]))];
        }
        if ($path === '/api/v1/retrievals/aged') {
            return [$this->response(200, [
                'to_handle' => 12,
                'buckets' => [
                    '30_60' => ['count' => 4, 'pieces' => 18, 'value' => 64500],
                    '61_90' => ['count' => 5, 'pieces' => 27, 'value' => 93000],
                    '90_plus' => ['count' => 3, 'pieces' => 12, 'value' => 41000],
                ],
                'items' => [[
                    'id' => 1, 'code' => 'BG-02588', 'client' => 'Aminata Diarra', 'phone_number' => '0748221903',
                    'ready_at' => '2026-09-19T10:00:00+00:00', 'retrieve_date' => '2026-09-20T10:00:00+00:00',
                    'waiting_days' => 31, 'pieces' => 7, 'value' => 18500, 'storage_fee' => 0,
                    'decision' => ['code' => 'remind', 'label' => 'Relancer'],
                ]],
            ])];
        }
        if (str_starts_with($path, '/api/v1/deposits/{') && (str_ends_with($path, '/notify') || str_ends_with($path, '/notification-read') || str_ends_with($path, '/aged-action'))) {
            return [$this->response(200, $deposit + ['notification' => $notification, 'open_url' => null])];
        }
        if (str_starts_with($path, '/api/v1/deposits/{') && str_ends_with($path, '/checkout')) {
            if ($method === 'GET') {
                return [$this->response(200, [
                    'deposit' => $deposit,
                    'header' => [
                        'client' => ['id' => 1, 'fullname' => 'Aminata Diarra', 'phone_number' => '0748221903', 'client_since' => '2025-03-02', 'loyalty_points' => 740, 'previous_unpaid_count' => 0, 'previous_unpaid_amount' => 0],
                        'received_by' => 'Grâce A.',
                        'order_label' => '7 pièces · Classique',
                        'badges' => [['code' => 'workshop_done', 'label' => 'Atelier terminé'], ['code' => 'due', 'label' => '4 000 FCFA dus']],
                    ],
                    'steps' => [
                        ['code' => 'in_progress', 'label' => 'Pris en charge', 'state' => 'done', 'at' => '2026-09-27T09:00:00+00:00', 'by' => 'Yao'],
                        ['code' => 'classed', 'label' => 'Prêt', 'state' => 'done', 'at' => '2026-09-28T08:46:00+00:00', 'by' => 'Grâce'],
                        ['code' => 'deposit.retrieved', 'label' => 'Remis', 'state' => 'current', 'at' => null, 'by' => null],
                    ],
                    'units' => [['id' => 1, 'designation' => 'Chemise homme', 'note' => 'Blanche · Taché', 'quantity' => 3, 'retrieved' => 0, 'remaining' => 3, 'verification' => ['code' => 'compliant', 'label' => 'Conforme']]],
                    'balance' => ['total' => 18500, 'paid' => 14500, 'left_to_pay' => 4000, 'blocked' => true, 'block_reason' => 'Retrait bloqué : solde impayé', 'can_override' => false],
                    'receiver' => ['types' => [['code' => 'client', 'label' => 'Client lui-même'], ['code' => 'third_party', 'label' => 'Tiers autorisé']], 'default_name' => 'Aminata Diarra'],
                    'conditions' => [['code' => 'compliant', 'label' => 'Conforme'], ['code' => 'client_reserve', 'label' => 'Réserve client'], ['code' => 'anomaly', 'label' => 'Anomalie constatée']],
                    'after_confirm' => ['print_slip' => ['enabled' => true, 'terminal' => 'POS-02'], 'notify_sms' => ['enabled' => true, 'destination' => '•••• 03']],
                    'audit' => [['at' => '2026-10-20T10:37:00+00:00', 'actor' => 'Grâce A.', 'action' => 'deposit.workshop', 'message' => 'État atelier passé à « Classé »']],
                ])];
            }

            return [$this->response(200, [
                'partial' => true,
                'change_due' => 0,
                'deposit' => $deposit,
                'slip_url' => 'https://api.example.com/api/v1/deposits/1/document?type=bon&format=pdf',
            ])];
        }

        // —— Dépôts ——
        if (str_starts_with($path, '/api/v1/deposits')) {
            if ($method === 'GET' && $path === '/api/v1/deposits') {
                return [$this->response(200, [$deposit], array_merge($pageMeta, ['kpis' => [
                    'deposits_today' => ['value' => 28, 'delta_percent' => 12.0, 'compared_to' => '2026-10-07'],
                    'revenue_today' => ['value' => 486750, 'collected' => 389500],
                    'to_retrieve_today' => ['value' => 14, 'ready' => 3],
                    'left_to_collect' => ['value' => 97250, 'open_deposits' => 11],
                    'refreshed_at' => '2026-10-14T10:34:00+00:00',
                ]]), description: 'Liste paginée + KPI')];
            }
            if ($method === 'GET' && $path === '/api/v1/deposits/export') {
                return [[
                    'status' => 200,
                    'description' => 'Export CSV (text/csv)',
                    'content' => json_encode(['data' => ['content_type' => 'text/csv'], 'meta' => (object) [], 'errors' => null], JSON_UNESCAPED_UNICODE),
                ]];
            }
            if ($method === 'POST' && str_ends_with($path, '/document/send')) {
                return [$this->response(200, [
                    'sent' => false,
                    'channel' => 'whatsapp',
                    'destination' => '0748221903',
                    'share_url' => 'https://api.example.com/api/v1/public/deposits/1/document?type=ticket&expires=…&signature=…',
                    'open_url' => 'https://wa.me/0748221903?text=…',
                ])];
            }
            if ($method === 'GET' && in_array($path, [
                '/api/v1/deposits/due',
                '/api/v1/deposits/retrieved',
                '/api/v1/deposits/ready',
            ], true)) {
                return [$this->response(200, [$deposit], $pageMeta)];
            }
            if ($method === 'GET' && $path === '/api/v1/deposits/{id}') {
                return [$this->response(200, $deposit + [
                    'invoice_number' => 'FAC-AK-261014-0001',
                    'commercial_status' => ['code' => 'open', 'label' => 'En cours', 'retrieve_date' => '2026-10-16T09:00:00+00:00'],
                    'workshop_detail' => ['status' => ['code' => 'classed', 'label' => 'Classé'], 'updated_at' => '2026-10-14T10:38:00+00:00'],
                    'pricing_mode' => 'piece',
                    'treatment' => ['express_hours' => 8, 'defects_reported' => true, 'renders' => [1], 'pieces_counted' => 7],
                    'pickup' => ['mode' => 'agency', 'label' => 'Retrait en agence', 'agency' => 'Akwa', 'retrieve_date' => '2026-10-16T09:00:00+00:00', 'address' => null],
                    'client_summary' => ['id' => 1, 'fullname' => 'Jean Client', 'phone_number' => '690000001', 'client_since' => '2025-03-02', 'loyalty_points' => 740, 'previous_unpaid_count' => 0, 'previous_unpaid_amount' => 0],
                    'totals' => ['currency' => 'XAF', 'subtotal' => 2500, 'express_supplement' => 1000, 'express_percent' => 100, 'fees' => 0, 'discount' => 0, 'points_used' => 0, 'points_discount' => 0, 'tax' => 0, 'total' => 3500, 'paid' => 1000, 'left_to_pay' => 2500],
                    'payments' => [['id' => 1, 'label' => 'Acompte', 'amount' => 1000, 'payment_method' => ['code' => 'cash', 'label' => 'Espèces'], 'at' => '2026-10-14T09:05:00+00:00', 'received_by' => 'Claire Caissier']],
                    'timeline' => [
                        ['at' => '2026-10-14T10:38:00+00:00', 'code' => 'classed', 'title' => 'Prêt pour retrait', 'detail' => 'Validé par Mariam K.'],
                        ['at' => '2026-10-14T09:00:00+00:00', 'code' => 'deposit.created', 'title' => 'Dépôt enregistré', 'detail' => 'Validé par Claire Caissier'],
                    ],
                    'audit' => [
                        ['at' => '2026-10-14T10:38:00+00:00', 'actor' => 'Mariam K.', 'action' => 'deposit.workshop', 'message' => 'État atelier passé à « Classé »'],
                        ['at' => '2026-10-14T09:00:00+00:00', 'actor' => 'Claire Caissier', 'action' => 'deposit.created', 'message' => 'Dépôt créé depuis POS-01'],
                    ],
                    'actions' => ['can_print' => true, 'can_edit' => true, 'can_add_payment' => true, 'can_cancel' => true, 'can_follow_workshop' => true],
                ])];
            }
            if ($method === 'GET' && $path === '/api/v1/deposits/qr/{id}') {
                return [$this->response(200, $deposit)];
            }
            if ($method === 'POST' && $path === '/api/v1/deposits') {
                return [$this->response(201, $deposit, description: 'Dépôt créé')];
            }
            if ($method === 'POST' && str_contains($path, '/payments')) {
                return [$this->response(200, array_merge($deposit, ['advanced' => 2000, 'left_to_pay' => 1500]))];
            }
            if ($method === 'POST' && (str_contains($path, '/retrieve') || str_contains($path, '/workshop'))) {
                return [$this->response(200, array_merge($deposit, ['etat' => 'ready', 'status' => 'closed']))];
            }
            if ($method === 'GET' && str_contains($path, '/document')) {
                return [$this->response(200, [
                    'type' => 'facture',
                    'currency' => 'XAF',
                    'pressing' => 'Élégance Pressing',
                    'agency' => 'Akwa',
                    'print_html' => '<!DOCTYPE html>…',
                    'deposit' => $deposit,
                    'document' => [
                        'format' => 'a4',
                        'number' => 'FAC-AK-261014-0001',
                        'issued_at' => '2026-10-14T10:40:00+00:00',
                        'payment_status' => ['code' => 'partial', 'label' => 'Partiellement payé'],
                        'seller' => ['name' => 'Élégance Pressing', 'slogan' => 'Soin textile premium', 'agency' => 'Akwa', 'address' => 'Douala, Akwa', 'phone' => '+237600000001', 'email' => 'akwa@elegance.cm', 'rccm' => 'RC-DLA-2024-B-01842', 'tax_id' => 'M0123456789'],
                        'customer' => ['fullname' => 'Jean Client', 'phone_number' => '690000001'],
                        'deposit' => ['code' => 'AK-0001-EL', 'date' => '2026-10-14T09:00:00+00:00', 'retrieve_date' => '2026-10-16T09:00:00+00:00'],
                        'lines' => [
                            ['designation' => 'Chemise', 'quantity' => 2, 'unit' => 'pièce', 'unit_price' => 1000, 'total' => 2000],
                            ['designation' => 'Majoration Express 8 h', 'quantity' => 1, 'unit' => 'forfait', 'unit_price' => 1000, 'total' => 1000],
                        ],
                        'totals' => ['currency' => 'XAF', 'subtotal' => 2500, 'express_supplement' => 1000, 'express_percent' => 100, 'fees' => 0, 'discount' => 0, 'points_used' => 0, 'points_discount' => 0, 'tax' => 0, 'total' => 3500, 'paid' => 1000, 'left_to_pay' => 2500],
                        'payments' => [['method' => 'Espèces', 'amount' => 1000]],
                        'terms' => 'TVA non applicable — régime simplifié.',
                        'qr' => 'qr-demo-token',
                        'footer' => 'Merci pour votre confiance.',
                    ],
                    'share_url' => 'https://api.example.com/api/v1/public/deposits/1/document?type=facture&expires=…&signature=…',
                ], description: 'JSON structuré ; `format=pdf` renvoie le binaire application/pdf')];
            }
            if ($method === 'DELETE') {
                return [$this->response(200, ['deleted' => true])];
            }
        }

        // —— Clients ——
        if (str_starts_with($path, '/api/v1/clients') || str_starts_with($path, '/api/v1/client/')) {
            if ($method === 'GET' && ($path === '/api/v1/clients' || $path === '/api/v1/clients/inactive')) {
                return [$this->response(200, [$clientRow], array_merge($pageMeta, $clientsMeta))];
            }
            if ($method === 'GET' && $path === '/api/v1/clients/export') {
                return [[
                    'status' => 200,
                    'description' => 'Export CSV (text/csv)',
                    'content' => json_encode(['data' => ['content_type' => 'text/csv'], 'meta' => (object) [], 'errors' => null], JSON_UNESCAPED_UNICODE),
                ]];
            }
            if ($method === 'GET' && $path === '/api/v1/clients/duplicates') {
                return [$this->response(200, ['has_duplicates' => true, 'matches' => [
                    ['id' => 1842, 'code' => 'CLI-01842', 'fullname' => 'Aminata Koné', 'phone_number' => '0748221603', 'agency' => 'Bingerville'],
                ]])];
            }
            if (str_ends_with($path, '/notes')) {
                $note = ['id' => 1, 'body' => 'Cliente attentive aux finitions.', 'author' => 'Fatou', 'created_at' => '2026-09-28T10:00:00+00:00'];

                return [$method === 'POST' ? $this->response(201, $note) : $this->response(200, [$note])];
            }
            if ($method === 'POST' && (str_ends_with($path, '/archive') || str_ends_with($path, '/restore'))) {
                return [$this->response(200, array_merge($clientRow, ['crm_status' => ['code' => 'archived', 'label' => 'Archivé']]))];
            }
            if ($method === 'POST' && str_ends_with($path, '/loyalty/convert')) {
                return [$this->response(200, ['converted_points' => 500, 'amount' => 2500, 'loyalty' => $clientProfile['loyalty'], 'wallet_balance' => 7500])];
            }
            if ($method === 'GET' && str_contains($path, '/loyalty')) {
                return [$this->response(200, [
                    'client_id' => 1,
                    'points' => 120,
                    'groups' => [['id' => 1, 'name' => 'Or']],
                ])];
            }
            if ($method === 'GET' && str_contains($path, '/wallet')) {
                return [$this->response(200, [
                    'client_id' => 1,
                    'balance' => 5000,
                    'currency' => 'XAF',
                ])];
            }
            if ($method === 'POST' && str_contains($path, '/wallet/')) {
                return [$this->response(200, [
                    'client_id' => 1,
                    'balance' => 6000,
                    'movement' => ['type' => 'credit', 'amount' => 1000],
                ])];
            }
            if ($method === 'GET' && str_contains($path, '/state')) {
                return [$this->response(200, [
                    'client' => $client,
                    'deposits_count' => 5,
                    'total_spent' => 85000,
                    'unpaid' => 2500,
                ])];
            }
            if ($method === 'GET' && str_contains($path, '/referrals')) {
                return [$this->response(200, [$client])];
            }
            if ($method === 'POST' && $path === '/api/v1/clients') {
                return [$this->response(201, $clientProfile)];
            }
            if ($method === 'GET' && $path === '/api/v1/clients/{id}') {
                return [$this->response(200, $clientProfile)];
            }
            if ($method === 'PUT' && $path === '/api/v1/clients/{id}') {
                return [$this->response(200, $clientProfile)];
            }
            if ($method === 'GET' && ($path === '/api/v1/clients/{id}' || $path === '/api/v1/client/profile' || str_contains($path, '/deposits') || str_contains($path, '/retrieves'))) {
                $data = str_contains($path, '/deposits') || str_contains($path, '/retrieves') ? [$deposit] : $client;

                return [$this->response(200, $data, str_contains($path, '/deposits') ? $pageMeta : [])];
            }
            if (in_array($method, ['PUT', 'PATCH'], true)) {
                return [$this->response(200, $client)];
            }
            if ($method === 'DELETE') {
                return [$this->response(200, ['deleted' => true])];
            }
        }

        // —— Catalogue ——
        if (str_starts_with($path, '/api/v1/articles')) {
            if ($method === 'GET' && $path === '/api/v1/articles') {
                return [$this->response(200, [$articleRow], array_merge($pageMeta, ['kpis' => [
                    'active_articles' => ['value' => 84, 'new_this_month' => 6],
                    'categories' => ['value' => 12, 'shared' => 2],
                    'average_price' => ['value' => 3280, 'delta_percent' => 4.2],
                    'to_review' => ['value' => 7, 'rule' => 'Tarifs > 12 mois'],
                    'updated_at' => '2026-09-28T09:42:00+00:00',
                ]]))];
            }
            if ($method === 'GET' && $path === '/api/v1/articles/history') {
                return [$this->response(200, [['at' => '2026-09-18T16:24:00+00:00', 'article_id' => 1, 'article' => 'Chemise', 'action' => 'article.updated', 'label' => 'Article modifié', 'changes' => ['express_price'], 'by' => 'Nadia Assi']])];
            }
            if ($method === 'GET' && $path === '/api/v1/articles/export') {
                return [$this->response(200, ['url' => '/storage/exports/articles.csv', 'rows' => 3])];
            }
            if ($method === 'GET' && $path === '/api/v1/articles/template') {
                return [$this->response(200, ['url' => '/storage/templates/articles.csv'])];
            }
            if ($method === 'POST' && $path === '/api/v1/articles/import') {
                return [$this->response(200, ['imported' => 3, 'failed' => 0])];
            }
            if ($method === 'POST') {
                return [$this->response(201, $articleDetail)];
            }
            if ($method === 'GET') {
                return [$this->response(200, $articleDetail)];
            }
            if (in_array($method, ['PUT', 'PATCH'], true)) {
                return [$this->response(200, $articleDetail)];
            }
            if ($method === 'DELETE') {
                return [$this->response(200, ['deleted' => true])];
            }
        }

        if (str_starts_with($path, '/api/v1/article-categories')) {
            $category = ['id' => 1, 'name' => 'Cérémonie', 'icon' => 'suit', 'shared' => true, 'sort_order' => 0, 'articles_count' => 6, 'active_articles_count' => 5];

            return [match ($method) {
                'GET' => $this->response(200, [$category]),
                'POST' => $this->response(201, $category),
                'DELETE' => $this->response(200, ['deleted' => true]),
                default => $this->response(200, $category),
            }];
        }

        if (preg_match('#^/api/v1/(services|laundry-statuses|renders|promos|promo-specials|delivery-hours|code-suffixes|loyal-groups|agency-prices|cash-categories)#', $path)) {
            $item = match (true) {
                str_contains($path, 'services') => $service,
                str_contains($path, 'laundry-statuses') => ['id' => 1, 'name' => 'Propre', 'code' => 'clean', 'status' => true],
                str_contains($path, 'renders') => ['id' => 1, 'title' => 'Plié', 'status' => true],
                str_contains($path, 'promo-specials') => ['id' => 1, 'title' => 'Promo spéciale', 'percent' => 10, 'status' => true],
                str_contains($path, 'promos') => ['id' => 1, 'code' => 'RENTREE15', 'percent' => 15, 'status' => true],
                str_contains($path, 'delivery-hours') => ['id' => 1, 'label' => '17h', 'hour' => '17:00', 'status' => true],
                str_contains($path, 'code-suffixes') => ['id' => 1, 'agency_id' => 1, 'suffix' => 'EL', 'status' => true],
                str_contains($path, 'loyal-groups') => ['id' => 1, 'name' => 'Or', 'min_points' => 100, 'status' => true],
                str_contains($path, 'agency-prices') => [
                    'id' => 1,
                    'agency_id' => 1,
                    'article_id' => 1,
                    'service_id' => 1,
                    'pricing_type' => 'piece',
                    'amount_minor' => 1200,
                    'valid_from' => '2026-01-01',
                    'valid_to' => null,
                ],
                default => ['id' => 1, 'name' => 'Achat fournitures', 'direction' => 'out', 'status' => true],
            };

            if ($method === 'GET' && ! str_contains($path, '{id}') && ! str_ends_with($path, '/resolve') && ! str_ends_with($path, '/clients')) {
                return [$this->response(200, [$item], $pageMeta)];
            }
            if ($method === 'GET' && str_ends_with($path, '/resolve')) {
                return [$this->response(200, $item)];
            }
            if ($method === 'GET' && str_ends_with($path, '/clients')) {
                return [$this->response(200, [$client])];
            }
            if ($method === 'POST' && str_contains($path, '/clients')) {
                return [$this->response(200, ['attached' => true])];
            }
            if ($method === 'DELETE' && str_contains($path, '/clients')) {
                return [$this->response(200, ['detached' => true])];
            }
            if ($method === 'POST') {
                return [$this->response(201, $item)];
            }
            if ($method === 'GET') {
                return [$this->response(200, $item)];
            }
            if (in_array($method, ['PUT', 'PATCH'], true)) {
                return [$this->response(200, $item)];
            }
            if ($method === 'DELETE') {
                return [$this->response(200, ['deleted' => true])];
            }
        }

        // —— Caisse ——
        if (str_starts_with($path, '/api/v1/cash-movements')) {
            if ($method === 'GET' && $path === '/api/v1/cash-movements') {
                return [$this->response(200, [$cashMovement], $pageMeta)];
            }
            if ($method === 'POST' && str_contains($path, '/validate')) {
                return [$this->response(200, array_merge($cashMovement, ['validated' => true]))];
            }
            if ($method === 'POST') {
                return [$this->response(201, $cashMovement)];
            }
            if (in_array($method, ['PUT', 'PATCH'], true)) {
                return [$this->response(200, $cashMovement)];
            }
            if ($method === 'DELETE') {
                return [$this->response(200, ['deleted' => true])];
            }
        }

        // —— Recettes ——
        if (str_starts_with($path, '/api/v1/receipts')) {
            return [$this->response(200, [
                'date' => '2026-10-15',
                'agency_id' => 1,
                'total_in' => 185000,
                'total_out' => 12000,
                'net' => 173000,
                'by_payment_method' => [
                    'cash' => 80000,
                    'mobile_money' => 90000,
                    'card' => 15000,
                ],
                'rows' => [$cashMovement],
            ])];
        }

        // —— Rapports ——
        if (str_starts_with($path, '/api/v1/reports')) {
            return [$this->response(200, [
                'from' => '2026-10-01',
                'to' => '2026-10-15',
                'totals' => ['revenue' => 2500000, 'orders' => 180, 'average_basket' => 13888],
                'rows' => [
                    ['label' => 'Akwa', 'revenue' => 1200000, 'orders' => 90],
                    ['label' => 'Bonabéri', 'revenue' => 1300000, 'orders' => 90],
                ],
            ])];
        }

        // —— Livraisons ——
        if (str_starts_with($path, '/api/v1/deliveries')) {
            $zone = ['id' => 1, 'name' => 'Centre-ville', 'code' => 'CV', 'agency_id' => 1, 'status' => true];
            $round = ['id' => 1, 'name' => 'Tournée matin', 'round_date' => '2026-10-15', 'status' => 'open', 'livreur_id' => 6];
            if (str_contains($path, '/zones')) {
                if ($method === 'GET') {
                    return [$this->response(200, [$zone])];
                }
                if ($method === 'POST') {
                    return [$this->response(201, $zone)];
                }
                if (in_array($method, ['PUT', 'PATCH'], true)) {
                    return [$this->response(200, $zone)];
                }
            }
            if (str_contains($path, '/rounds')) {
                if ($method === 'GET') {
                    return [$this->response(200, [$round])];
                }
                if ($method === 'POST') {
                    return [$this->response(201, $round)];
                }
            }
            if (str_contains($path, '/queue') || str_contains($path, '/history')) {
                return [$this->response(200, [$deposit], $pageMeta)];
            }
            if ($method === 'POST') {
                return [$this->response(200, array_merge($deposit, ['delivery_status' => 'delivered']))];
            }
        }

        // —— Organisation / settings / roles ——
        if (str_starts_with($path, '/api/v1/org/') || str_starts_with($path, '/api/v1/settings') || str_starts_with($path, '/api/v1/roles') || str_starts_with($path, '/api/v1/permissions')) {
            if (str_contains($path, 'exchange-rates')) {
                $rate = [
                    'id' => 1,
                    'source_currency' => 'XAF',
                    'target_currency' => 'EUR',
                    'rate' => 655.957,
                    'rate_date' => '2026-10-15',
                ];
                if ($method === 'GET') {
                    return [$this->response(200, [$rate])];
                }
                if ($method === 'POST') {
                    return [$this->response(201, $rate)];
                }
                if ($method === 'DELETE') {
                    return [$this->response(200, ['deleted' => true])];
                }
            }
            if (str_contains($path, 'license')) {
                return [$this->response(200, [
                    'plan' => 'pro',
                    'seats' => 10,
                    'is_activated' => true,
                    'expires_at' => '2027-10-15T00:00:00+00:00',
                ])];
            }
            if (str_contains($path, 'pressing') && $method === 'GET') {
                return [$this->response(200, $pressing)];
            }
            if (str_contains($path, 'pressing') && in_array($method, ['PUT', 'PATCH'], true)) {
                return [$this->response(200, $pressing)];
            }
            if (str_contains($path, 'agencies') || str_contains($path, '/agencies/')) {
                if ($method === 'GET' && ! str_contains($path, '{id}')) {
                    return [$this->response(200, [$agency], $pageMeta)];
                }
                if ($method === 'POST') {
                    return [$this->response(201, $agency)];
                }

                return [$this->response(200, $agency)];
            }
            if (str_contains($path, 'users')) {
                if ($method === 'GET' && ! str_contains($path, '{id}')) {
                    return [$this->response(200, [$user], $pageMeta)];
                }
                if ($method === 'POST') {
                    return [$this->response(201, $user)];
                }
                if ($method === 'DELETE') {
                    return [$this->response(200, ['deleted' => true])];
                }

                return [$this->response(200, $user)];
            }
            if (str_contains($path, 'permissions')) {
                return [$this->response(200, ['deposits.view', 'deposits.create', 'cash.manage', 'reports.view'])];
            }
            if (str_contains($path, 'roles')) {
                if ($method === 'GET') {
                    return [$this->response(200, [
                        ['id' => 1, 'name' => 'caissier', 'permissions' => ['deposits.view', 'cash.manage']],
                        ['id' => 2, 'name' => 'manager', 'permissions' => ['reports.view', 'settings.manage']],
                    ])];
                }

                return [$this->response(200, ['id' => 1, 'name' => 'caissier', 'permissions' => ['deposits.view']])];
            }
        }

        // —— Audit ——
        if (str_starts_with($path, '/api/v1/audit-logs') || str_starts_with($path, '/api/v1/print-logs')) {
            return [$this->response(200, [
                [
                    'id' => 1,
                    'action' => 'deposit.created',
                    'actor_type' => 'staff',
                    'actor_id' => 1,
                    'subject_type' => 'deposit',
                    'subject_id' => 1,
                    'created_at' => '2026-10-15T10:00:00+00:00',
                ],
            ], $pageMeta)];
        }

        // —— Superadmin ——
        if (str_starts_with($path, '/api/v1/superadmin')) {
            if (str_contains($path, 'pressings')) {
                if ($method === 'GET' && ! str_contains($path, '{id}')) {
                    return [$this->response(200, [$pressing], $pageMeta)];
                }
                if ($method === 'POST') {
                    return [$this->response(201, $pressing)];
                }
                if ($method === 'DELETE') {
                    return [$this->response(200, ['deleted' => true])];
                }

                return [$this->response(200, $pressing)];
            }
            if (str_contains($path, 'agencies')) {
                if ($method === 'GET' && ! str_contains($path, '{id}')) {
                    return [$this->response(200, [$agency], $pageMeta)];
                }
                if ($method === 'POST') {
                    return [$this->response(201, $agency)];
                }

                return [$this->response(200, $agency)];
            }
            if (str_contains($path, 'users')) {
                if ($method === 'GET') {
                    return [$this->response(200, [$user], $pageMeta)];
                }
                if ($method === 'POST') {
                    return [$this->response(201, $user)];
                }
                if ($method === 'DELETE') {
                    return [$this->response(200, ['deleted' => true])];
                }

                return [$this->response(200, $user)];
            }
            if (str_contains($path, 'licenses')) {
                return $this->licenseResponses($path, $method, $pageMeta);
            }
            if (str_contains($path, 'roles') || str_contains($path, 'permissions')) {
                return [$this->response(200, [['id' => 1, 'name' => 'admin']])];
            }
        }

        return [];
    }

    /**
     * Centre des licences superadmin (Figma 13C).
     *
     * @return list<array{status:int,description:string,content:string}>
     */
    private function licenseResponses(string $path, string $method, array $pageMeta): array
    {
        $row = [
            'id' => 184,
            'reference' => 'LIC-CI-00184',
            'code' => 'K3J9QF2LZ8XA',
            'pressing_id' => 1,
            'pressing' => ['id' => 1, 'name' => 'SPARK PRESSING CI', 'initials' => 'SP', 'country_code' => 'CI', 'country' => 'Côte d’Ivoire'],
            'plan' => ['code' => 'business', 'label' => 'Business'],
            'periodicity' => ['code' => 'annual', 'label' => 'Annuel'],
            'seats' => 100,
            'is_activated' => true,
            'is_valid' => true,
            'auto_renew' => true,
            'activated_at' => '2026-01-01T00:00:00+00:00',
            'expires_at' => '2026-12-31T00:00:00+00:00',
            'days_left' => 94,
            'period' => ['start' => '2026-01-01', 'end' => '2026-12-31', 'days_total' => 364, 'days_left' => 94, 'consumed_percent' => 74],
            'usage' => ['agencies' => ['used' => 4, 'limit' => 6], 'users' => ['used' => 58, 'limit' => 100]],
            'status' => ['code' => 'active', 'label' => 'Active'],
            'payment_status' => ['code' => 'paid', 'label' => 'Payé'],
            'suspended_at' => null,
            'suspension_reason' => null,
        ];
        $payment = [
            'id' => 184,
            'date' => '2026-09-28T10:42:00+00:00',
            'period' => ['periodicity' => ['code' => 'annual', 'label' => 'Annuel'], 'label' => 'Annuel · 2027', 'start' => '2027-01-01', 'end' => '2028-01-01'],
            'plan' => ['code' => 'business', 'label' => 'Business'],
            'amount' => 150000,
            'taxes' => 0,
            'total' => 150000,
            'currency' => 'XOF',
            'payment_method' => ['code' => 'cash', 'label' => 'Espèces'],
            'reference' => 'TRX-2026-09-2841',
            'invoice_number' => 'FAC-2026-184',
            'status' => ['code' => 'pending', 'label' => 'En attente'],
            'has_proof' => true,
            'paid_at' => null,
            'invoice_url' => 'https://api.example.com/api/v1/superadmin/licenses/184/payments/184/invoice',
        ];
        $offer = fn (string $code, string $label, int $months, int $days, int $amount, int $savings, int $free, bool $reco) => [
            'periodicity' => $code, 'label' => $label, 'months' => $months, 'days' => $days, 'amount' => $amount,
            'taxes' => 0, 'total' => $amount, 'savings_percent' => $savings, 'free_months' => $free,
            'recommended' => $reco, 'current' => $code === 'annual',
        ];
        $detail = [
            'license' => $row,
            'renewal' => [
                'currency' => 'XOF',
                'tax_rate' => 0,
                'plan' => ['code' => 'business', 'label' => 'Business'],
                'plans' => [[
                    'code' => 'business', 'label' => 'Business',
                    'prices' => ['monthly' => 15000, 'quarterly' => 40000, 'annual' => 150000],
                    'limits' => ['agencies' => 6, 'users' => 100, 'storage_gb' => 5],
                ]],
                'offers' => [
                    $offer('monthly', 'Mensuel', 1, 30, 15000, 0, 0, false),
                    $offer('quarterly', 'Trimestriel', 3, 90, 40000, 11, 0, false),
                    $offer('annual', 'Annuel', 12, 365, 150000, 17, 2, true),
                ],
                'payment_methods' => [
                    ['code' => 'cash', 'label' => 'Espèces'], ['code' => 'card', 'label' => 'Carte'],
                    ['code' => 'flooz', 'label' => 'Flooz'], ['code' => 'tmoney', 'label' => 'T-Money'],
                    ['code' => 'mobile_money', 'label' => 'Mobile Money'],
                ],
                'defaults' => ['periodicity' => 'annual', 'payment_method' => 'cash', 'reference' => 'TRX-2026-09-2841', 'effective_at' => '2027-01-01', 'auto_renew' => true],
                'proof' => ['accepted' => ['pdf', 'jpg', 'jpeg', 'png'], 'max_size_mb' => 10],
            ],
            'coverage' => [
                'agencies_count' => 4,
                'active_users' => 58,
                'agencies' => [
                    ['id' => 1, 'name' => 'Cocody', 'users_count' => 24, 'covered' => true],
                    ['id' => 2, 'name' => 'Plateau', 'users_count' => 14, 'covered' => true],
                ],
            ],
            'limits' => [
                'plan' => ['code' => 'business', 'label' => 'Business'],
                'items' => [
                    ['key' => 'agencies', 'label' => 'Agences', 'used' => 4, 'limit' => 6, 'unit' => null, 'percent' => 67],
                    ['key' => 'users', 'label' => 'Utilisateurs', 'used' => 58, 'limit' => 100, 'unit' => null, 'percent' => 58],
                    ['key' => 'storage', 'label' => 'Stockage', 'used' => 2.8, 'limit' => 5, 'unit' => 'Go', 'percent' => 56],
                ],
                'features' => ['Multi-agences', 'Rapports avancés', 'Offline 7 jours', 'API', 'Support prioritaire'],
            ],
            'payments' => [$payment],
            'audit' => [
                'status' => ['code' => 'compliant', 'label' => 'Conforme'],
                'last_action' => ['action' => 'license.renewed', 'label' => 'Préparation du renouvellement', 'actor' => 'Aïcha N.', 'at' => '2026-09-28T10:42:00+00:00'],
                'checks' => [
                    ['code' => 'reference', 'label' => 'Référence TRX-2026-09-2841 enregistrée', 'ok' => true],
                    ['code' => 'proof', 'label' => 'Justificatif en attente de validation', 'ok' => true],
                ],
            ],
            'actions' => ['can_renew' => true, 'can_change_plan' => true, 'can_suspend' => true, 'can_reactivate' => false, 'invoice_payment_id' => 184],
        ];
        $binary = fn (string $type) => [[
            'status' => 200,
            'description' => "Fichier ({$type})",
            'content' => json_encode([
                'data' => ['content_type' => $type, 'note' => 'Réponse binaire'],
                'meta' => (object) [],
                'errors' => null,
            ], JSON_UNESCAPED_UNICODE),
        ]];

        return match (true) {
            str_ends_with($path, '/overview') => [$this->response(200, [
                'period' => ['code' => 'month', 'from' => '2026-10-01T00:00:00+00:00', 'to' => '2026-10-21T10:34:00+00:00'],
                'currency' => 'XOF',
                'network' => ['licenses' => 42, 'countries' => 7, 'updated_at' => '2026-10-21T10:34:00+00:00'],
                'kpis' => [
                    'active' => ['value' => 36, 'share_percent' => 85.7],
                    'expiring_soon' => ['value' => 4, 'within_days' => 30],
                    'suspended' => ['value' => 2, 'critical_unpaid' => 1],
                    'renewals' => ['value' => 12, 'delta_percent' => 20.0],
                    'revenue' => ['value' => 4860000, 'currency' => 'XOF', 'delta_percent' => 8.4],
                ],
                'deadlines' => [
                    'window_days' => 30, 'count' => 2, 'auto_renew_ready' => 2,
                    'items' => [['license_id' => 12, 'reference' => 'LIC-SN-00012', 'pressing' => ['id' => 3, 'name' => 'Luxe Press'], 'expires_at' => '2026-10-23T00:00:00+00:00', 'days_left' => 2, 'auto_renew' => true]],
                ],
                'forecast' => ['month' => '2026-10', 'amount' => 1245000, 'currency' => 'XOF', 'renewals_expected' => 17, 'secured_percent' => 82],
            ])],
            str_ends_with($path, '/export') => $binary('text/csv'),
            str_ends_with($path, '/invoice') => $binary('application/pdf'),
            str_ends_with($path, '/validate') => [$this->response(200, array_merge($payment, ['status' => ['code' => 'paid', 'label' => 'Payé'], 'paid_at' => '2026-09-28T11:00:00+00:00']))],
            str_ends_with($path, '/payments') => [$this->response(200, [$payment], array_merge($pageMeta, ['per_page' => 20]))],
            str_ends_with($path, '/renew') => [$this->response(201, $detail)],
            str_ends_with($path, '/suspend') => [$this->response(200, array_merge($row, ['status' => ['code' => 'suspended', 'label' => 'Suspendue'], 'suspended_at' => '2026-10-21T10:34:00+00:00', 'suspension_reason' => 'Impayé']))],
            $method === 'DELETE' => [$this->response(200, ['deleted' => true])],
            $method === 'POST' && str_ends_with($path, '/licenses') => [$this->response(201, $row)],
            $method === 'GET' && str_ends_with($path, '/licenses') => [$this->response(200, [$row], $pageMeta)],
            $method === 'GET' => [$this->response(200, $detail)],
            default => [$this->response(200, $row)],
        };
    }

    /** @return array{status:int,description:string,content:string} */
    private function fallback(string $method): array
    {
        return match ($method) {
            'POST' => $this->response(201, ['id' => 1, 'status' => true], description: 'Créé'),
            'DELETE' => $this->response(200, ['deleted' => true], description: 'Supprimé'),
            default => $this->response(200, ['ok' => true], description: 'Succès'),
        };
    }

    /**
     * @param  array<string,mixed>|list<mixed>|null  $data
     * @param  array<string,mixed>  $meta
     * @param  list<array<string,mixed>>|null  $errors
     * @return array{status:int,description:string,content:string}
     */
    private function response(
        int $status,
        mixed $data,
        array $meta = [],
        ?array $errors = null,
        string $description = 'Succès',
    ): array {
        return [
            'status' => $status,
            'description' => $description,
            'content' => json_encode([
                'data' => $data,
                'meta' => empty($meta) ? (object) [] : $meta,
                'errors' => $errors,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        ];
    }
}
