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
            'code' => 'C000001',
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
        ];

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
                    'loyalty_clients' => 312,
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
                    'revenue' => 983500,
                    'revenue_delta_pct' => 8.2,
                    'deposits' => 42,
                    'retrieves' => 38,
                    'unpaid_count' => 5,
                    'unpaid_amount' => 45000,
                    'cash_balance' => 900000,
                ]],
                'workshop_pipeline' => [
                    'in_progress_deposits' => 98,
                    'in_progress_pieces' => 320,
                    'columns' => [
                        ['etat' => 'waiting', 'colonne' => 'attente', 'label' => 'À trier', 'deposits' => 14, 'pieces' => 46],
                    ],
                    'due_soon' => [],
                ],
                'cash' => [
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
                    'overdue_count' => 18,
                    'buckets' => [
                        '0_30' => ['count' => 32, 'amount' => 420000],
                        '31_89' => ['count' => 41, 'amount' => 540000],
                        '90_plus' => ['count' => 21, 'amount' => 280000],
                    ],
                ],
                'recent_activity' => [],
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

        if ($path === '/api/v1/deposits/workshop') {
            return [$this->response(200, [
                'columns' => [
                    ['etat' => 'waiting', 'colonne' => 'attente', 'label' => 'À trier', 'count' => 2],
                ],
                'board' => [
                    'attente' => [$deposit + ['etat_label' => 'À trier', 'colonne' => 'attente', 'priorite' => 'Haute']],
                    'cours' => [],
                    'traites' => [],
                    'classes' => [],
                ],
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

        // —— Dépôts ——
        if (str_starts_with($path, '/api/v1/deposits')) {
            if ($method === 'GET' && $path === '/api/v1/deposits') {
                return [$this->response(200, [$deposit], $pageMeta, description: 'Liste paginée')];
            }
            if ($method === 'GET' && in_array($path, [
                '/api/v1/deposits/due',
                '/api/v1/deposits/retrieved',
                '/api/v1/deposits/ready',
            ], true)) {
                return [$this->response(200, [$deposit], $pageMeta)];
            }
            if ($method === 'GET' && ($path === '/api/v1/deposits/{id}' || $path === '/api/v1/deposits/qr/{id}')) {
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
                return [[
                    'status' => 200,
                    'description' => 'PDF ticket/facture (application/pdf)',
                    'content' => json_encode([
                        'data' => ['content_type' => 'application/pdf', 'note' => 'Réponse binaire PDF'],
                        'meta' => (object) [],
                        'errors' => null,
                    ], JSON_UNESCAPED_UNICODE),
                ]];
            }
            if ($method === 'DELETE') {
                return [$this->response(200, ['deleted' => true])];
            }
        }

        // —— Clients ——
        if (str_starts_with($path, '/api/v1/clients') || str_starts_with($path, '/api/v1/client/')) {
            if ($method === 'GET' && ($path === '/api/v1/clients' || $path === '/api/v1/clients/inactive')) {
                return [$this->response(200, [$client], $pageMeta)];
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
                return [$this->response(201, $client)];
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
                return [$this->response(200, [$article], $pageMeta)];
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
                return [$this->response(201, $article)];
            }
            if ($method === 'GET') {
                return [$this->response(200, $article)];
            }
            if (in_array($method, ['PUT', 'PATCH'], true)) {
                return [$this->response(200, $article)];
            }
            if ($method === 'DELETE') {
                return [$this->response(200, ['deleted' => true])];
            }
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
