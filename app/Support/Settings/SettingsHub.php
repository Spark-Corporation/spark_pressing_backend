<?php

namespace App\Support\Settings;

use App\Models\Agency;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\AuditLog;
use App\Models\DeliveryZone;
use App\Models\NotificationLog;
use App\Models\PosDevice;
use App\Models\PressingSetting;
use App\Models\Promo;
use App\Models\User;
use App\Support\Notifications\Notifier;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** Hub « Paramètres » (Figma 09) : cartes par groupe, état global, contexte des sous-écrans. */
class SettingsHub
{
    public const GROUP_LABELS = ['structure' => 'Structure', 'operations' => 'Opérations', 'finance' => 'Finance & services', 'platform' => 'Plateforme'];

    /** Carte → libellé, catégorie de réglages qui porte sa date de modification, écran cible. */
    private const CARDS = [
        'organisation' => ['Organisation & agences', null, '/api/v1/org/agencies/overview'],
        'users' => ['Utilisateurs & rôles', null, '/api/v1/org/users'],
        'catalog' => ['Catalogue', null, '/api/v1/articles'],
        'deposit_codes' => ['Codes dépôt', null, '/api/v1/org/agencies'],
        'delays' => ['Délais de traitement', 'operations', '/api/v1/settings/operations'],
        'promotions' => ['Promotions', null, '/api/v1/promos'],
        'loyalty' => ['Fidélité', 'loyalty', '/api/v1/settings/loyalty'],
        'workshop_cycle' => ['Cycle atelier', 'operations', '/api/v1/settings/operations'],
        'pricing' => ['Tarification', 'operations', '/api/v1/settings/operations'],
        'delivery' => ['Collecte & livraison', 'delivery', '/api/v1/settings/delivery'],
        'payments' => ['Paiements', 'payments', '/api/v1/settings/payments'],
        'notifications' => ['Notifications', 'notifications', '/api/v1/settings/notifications'],
        'branding' => ['Branding', 'branding', '/api/v1/settings/branding'],
        'security' => ['Sécurité', 'security', '/api/v1/settings/security'],
        'offline' => ['Mode hors ligne', 'offline', '/api/v1/settings/offline'],
        'audit' => ['Audit & conservation', 'audit', '/api/v1/settings/audit'],
    ];

    private const METHOD_LABELS = ['cash' => 'Espèces', 'card' => 'Carte', 'mobile_money' => 'Mobile Money', 'wallet' => 'Portefeuille'];

    public function __construct(private readonly SettingsRegistry $settings) {}

    public function overview(int $pressingId, ?string $group = null, ?string $q = null): array
    {
        $rows = PressingSetting::query()->where('pressing_id', $pressingId)->get()->keyBy('category');
        $cards = [];
        foreach (config('settings_schema.hub') as $groupCode => $keys) {
            foreach ($keys as $key) {
                $cards[] = $this->card($pressingId, $groupCode, $key, $rows);
            }
        }

        $visible = collect($cards)
            ->when($group, fn ($c) => $c->where('group', $group))
            ->when($q, fn ($c) => $c->filter(fn ($card) => str_contains(mb_strtolower($card['label'].' '.$card['summary']), mb_strtolower($q))))
            ->values();
        $toCheck = collect($cards)->where('status', '!=', 'up_to_date')->count();

        return [
            'summary' => [
                'categories' => count($cards),
                'up_to_date' => count($cards) - $toCheck,
                'to_check' => $toCheck,
                'action_required' => collect($cards)->where('status', 'action_required')->count(),
                'label' => sprintf('%d catégories contrôlées · %d à jour · %d à vérifier', count($cards), count($cards) - $toCheck, $toCheck),
            ],
            'filters' => collect(self::GROUP_LABELS)->map(fn ($label, $code) => ['code' => $code, 'label' => $label, 'count' => collect($cards)->where('group', $code)->count()])->values()->all(),
            'groups' => collect(self::GROUP_LABELS)
                ->map(fn ($label, $code) => ['code' => $code, 'label' => $label, 'cards' => $visible->where('group', $code)->values()->all()])
                ->filter(fn ($g) => $g['cards'])->values()->all(),
            'recent_changes' => $this->recentChanges($pressingId),
        ];
    }

    /** Données d’appui affichées sur chaque sous-écran (impact, appareils, aperçus…). */
    public function context(int $pressingId, string $category): array
    {
        $agencies = Agency::query()->where('pressing_id', $pressingId)->where('status', true);

        return match ($category) {
            'operations' => [
                'agencies' => (clone $agencies)->orderBy('name')->get(['id', 'name', 'code_prefix', 'code_suffix'])
                    ->map(fn ($a) => ['agency_id' => $a->id, 'name' => $a->name, 'prefix' => $a->code_prefix, 'suffix' => $a->code_suffix,
                        'preview' => sprintf('%s-%05d%s', $a->code_prefix ?: 'DP', 2684, $a->code_suffix ? '-'.$a->code_suffix : '')])->all(),
                'workflow_preview' => $this->workflowPreview($pressingId),
            ],
            'loyalty' => [
                'members' => DB::table('clients')->where('pressing_id', $pressingId)->where('loyalty_points', '>', 0)->count(),
                'points_outstanding' => (int) DB::table('clients')->where('pressing_id', $pressingId)->sum('loyalty_points'),
            ],
            'delivery' => [
                'zones' => DeliveryZone::query()->withoutGlobalScopes()->where('pressing_id', $pressingId)->whereNull('deleted_at')->count(),
                'agencies' => (clone $agencies)->orderBy('name')->get(['id', 'name'])->all(),
            ],
            'notifications' => $this->notificationsContext($pressingId),
            'branding', 'receipts' => [
                'impact' => ['agencies' => (clone $agencies)->count(), 'surfaces' => $category === 'branding' ? ['navigation', 'tickets', 'emails_sms', 'domain'] : ['tickets', 'invoices', 'receipts']],
                'protected_rules' => $category === 'receipts' ? ['Les notes internes ne sont jamais imprimées.'] : [],
            ],
            'security' => $this->securityImpact($pressingId),
            'offline' => [
                'devices' => PosDevice::query()->withoutGlobalScopes()->with(['agency:id,name', 'responsible:id,fullname'])->where('pressing_id', $pressingId)->orderBy('code')->get()->map(fn ($d) => self::device($d))->all(),
                'cache' => [
                    'articles' => Article::query()->withoutGlobalScopes()->where('pressing_id', $pressingId)->where('status', true)->whereNull('deleted_at')->count(),
                    'recent_clients' => DB::table('clients')->where('pressing_id', $pressingId)->where('updated_at', '>=', now()->subDays((int) $this->settings->value($pressingId, 'offline', 'cache.recent_clients_days', 30)))->count(),
                ],
                'unavailable_offline' => ['Finance sensible', 'Réglages & administration', 'Atelier'],
            ],
            'audit' => [
                'agencies' => (clone $agencies)->count(),
                'events' => AuditLog::query()->where('pressing_id', $pressingId)->where('created_at', '>=', now()->subMonths((int) $this->settings->value($pressingId, 'audit', 'retention_months', 24)))->count(),
            ],
            default => [],
        };
    }

    public static function device(PosDevice $d): array
    {
        $status = $d->effectiveStatus();

        return [
            'id' => $d->id,
            'code' => $d->code,
            'type' => $d->type,
            'label' => $d->label,
            'agency' => $d->agency ? ['id' => $d->agency->id, 'name' => $d->agency->name] : null,
            'responsible' => $d->responsible ? ['id' => $d->responsible->id, 'fullname' => $d->responsible->fullname] : null,
            'status' => $status,
            'status_label' => PosDevice::STATUS_LABELS[$status] ?? $status,
            'offline_enabled' => $d->offline_enabled,
            'authorized_at' => $d->authorized_at?->toIso8601String(),
            'expires_at' => $d->expires_at?->toIso8601String(),
            'last_seen_at' => $d->last_seen_at?->toIso8601String(),
        ];
    }

    private function card(int $pressingId, string $group, string $key, $rows): array
    {
        [$label, $category, $endpoint] = self::CARDS[$key];
        [$summary, $actionRequired, $updatedAt] = $this->describe($pressingId, $key);
        $row = $category ? $rows->get($category) : null;
        $updatedAt = $row?->updated_at ?? $updatedAt;
        $status = match (true) {
            $actionRequired => 'action_required',
            (bool) $row?->draft, $updatedAt && $updatedAt->gt(now()->subDay()) => 'modified',
            default => 'up_to_date',
        };

        return [
            'key' => $key,
            'group' => $group,
            'label' => $label,
            'summary' => $summary,
            'status' => $status,
            'status_label' => ['action_required' => 'Action requise', 'modified' => 'Modifié', 'up_to_date' => 'À jour'][$status],
            'updated_at' => $updatedAt?->toIso8601String(),
            'updated_relative' => $updatedAt ? 'Modifié '.$updatedAt->locale('fr')->diffForHumans() : null,
            'endpoint' => $endpoint,
        ];
    }

    /** @return array{0:string,1:bool,2:?CarbonInterface} */
    private function describe(int $pressingId, string $key): array
    {
        $ops = fn () => $this->settings->get($pressingId, 'operations');

        return match ($key) {
            'organisation' => [Agency::query()->where('pressing_id', $pressingId)->where('status', true)->count().' agences actives', false, Agency::query()->where('pressing_id', $pressingId)->latest('updated_at')->first()?->updated_at],
            'users' => $this->usersCard($pressingId),
            'catalog' => [sprintf('%d articles · %d catégories',
                Article::query()->withoutGlobalScopes()->where('pressing_id', $pressingId)->whereNull('deleted_at')->count(),
                ArticleCategory::query()->withoutGlobalScopes()->where(fn ($q) => $q->where('pressing_id', $pressingId)->orWhere('shared', true))->count()), false, null],
            'deposit_codes' => [Agency::query()->where('pressing_id', $pressingId)->whereNotNull('code_prefix')->count().' modèles par agence', false, null],
            'delays' => [sprintf('Classique %d h, Express %d h, Repassage %d h', ...array_values($ops()['delays'])), false, null],
            'promotions' => [Promo::query()->withoutGlobalScopes()->where('pressing_id', $pressingId)->get()->filter->isUsable()->count().' campagnes actives', false, null],
            'loyalty' => (function () use ($pressingId) {
                $p = $this->settings->get($pressingId, 'loyalty')['program'];

                return [$p['active'] ? sprintf('%d pts / 1 000 FCFA · seuil %s pts', $p['earn_points_per_1000'], number_format($p['minimum_points'], 0, ',', ' ')) : 'Programme désactivé', false, null];
            })(),
            'workshop_cycle' => [match ([$ops()['workshop']['washer_step'], $ops()['workshop']['sorter_step']]) {
                [true, true] => 'Laveur et Classeur actifs', [true, false] => 'Laveur actif', [false, true] => 'Classeur actif', default => 'Cycle simplifié',
            }, false, null],
            'pricing' => [sprintf('%s · express +%d %%', ['piece' => 'À la pièce', 'kilo' => 'Au kilo', 'mixed' => 'Mixte'][$ops()['pricing']['mode']] ?? $ops()['pricing']['mode'], $ops()['pricing']['express_surcharge_percent']), false, null],
            'delivery' => (function () use ($pressingId) {
                $zones = DeliveryZone::query()->withoutGlobalScopes()->where('pressing_id', $pressingId)->whereNull('deleted_at')->where('status', true)->count();
                $d = $this->settings->get($pressingId, 'delivery');
                $enabled = $d['collection_enabled'] || $d['delivery_enabled'];

                return [$enabled ? "{$zones} zones · frais configurés" : 'Service désactivé', $enabled && $zones === 0, null];
            })(),
            'payments' => [collect($this->settings->get($pressingId, 'payments')['methods'])->filter()->keys()->map(fn ($m) => self::METHOD_LABELS[$m] ?? $m)->implode(', '), false, null],
            'notifications' => (function () use ($pressingId) {
                $events = collect($this->settings->get($pressingId, 'notifications')['events']);
                $sms = $events->filter(fn ($e) => $e['sms'])->count();
                $mail = $events->filter(fn ($e) => $e['email'])->count();

                return [match (true) {
                    $sms && $mail => 'SMS et e-mail automatiques', (bool) $sms => 'SMS automatiques', (bool) $mail => 'E-mails automatiques', default => 'Aucune notification'
                }, false, null];
            })(),
            'branding' => [$this->settings->get($pressingId, 'branding')['name'].' · '.$this->settings->get($pressingId, 'branding')['primary_color'], false, null],
            'security' => (function () use ($pressingId) {
                $impact = $this->securityImpact($pressingId);

                return [sprintf('MFA requis pour %d comptes', $impact['mfa_required']), $impact['devices_to_approve'] > 0, null];
            })(),
            'offline' => [$this->settings->value($pressingId, 'offline', 'enabled') ? sprintf('File locale · synchro auto %d min', $this->settings->value($pressingId, 'offline', 'sync_interval_minutes')) : 'Désactivé', false, null],
            'audit' => ['Conservation '.$this->settings->value($pressingId, 'audit', 'retention_months').' mois', false, null],
        };
    }

    private function usersCard(int $pressingId): array
    {
        $users = User::query()->where('pressing_id', $pressingId)->where('status', true)->with('roles:id,name')->get(['id']);
        $roles = $users->flatMap(fn ($u) => $u->roles->pluck('name'))->unique()->count();
        $withoutRole = $users->filter(fn ($u) => $u->roles->isEmpty())->count();

        return [sprintf('%d utilisateurs · %d rôles', $users->count(), $roles), $withoutRole > 0, null];
    }

    private function securityImpact(int $pressingId): array
    {
        $required = (array) $this->settings->value($pressingId, 'security', 'mfa.required_roles', []);
        $users = User::query()->where('pressing_id', $pressingId)->where('status', true)->with('roles:id,name')->get(['id', 'agency_id']);

        return [
            'staff_accounts' => $users->count(),
            'agencies' => Agency::query()->where('pressing_id', $pressingId)->where('status', true)->count(),
            'mfa_required' => $users->filter(fn ($u) => $u->roles->pluck('name')->intersect($required)->isNotEmpty())->count(),
            'roles' => $users->flatMap(fn ($u) => $u->roles->pluck('name'))->countBy()
                ->map(fn ($count, $role) => ['role' => $role, 'accounts' => $count, 'mfa_required' => in_array($role, $required, true)])->values()->all(),
            'devices_to_approve' => PosDevice::query()->withoutGlobalScopes()->where('pressing_id', $pressingId)->where('status', 'pending')->count(),
        ];
    }

    /** Cartes fournisseurs (taux de livraison sur 7 jours), variables et taille des modèles. */
    private function notificationsContext(int $pressingId): array
    {
        $since = now()->subDays(7);
        $stats = NotificationLog::query()->where('pressing_id', $pressingId)->where('is_test', false)->where('created_at', '>=', $since)
            ->selectRaw('channel, status, count(*) as total')->groupBy('channel', 'status')->get()->groupBy('channel');
        $provider = function (string $channel) use ($stats) {
            $rows = $stats->get($channel, collect());
            $total = (int) $rows->sum('total');
            $ok = (int) $rows->whereIn('status', ['sent', 'delivered', 'opened'])->sum('total');

            return ['sent_7d' => $total, 'delivered_percent' => $total ? round($ok / $total * 100, 1) : null,
                'opened_percent' => $channel === 'email' && $total ? round((int) $rows->where('status', 'opened')->sum('total') / $total * 100, 1) : null,
                'bounce_percent' => $channel === 'email' && $total ? round((int) $rows->where('status', 'bounced')->sum('total') / $total * 100, 1) : null];
        };
        $config = $this->settings->get($pressingId, 'notifications');

        return [
            'providers' => [
                'sms' => ['name' => $config['providers']['sms'], 'integrated' => false] + $provider('sms'),
                'email' => ['name' => $config['providers']['email'], 'integrated' => true] + $provider('email'),
                'future' => [['code' => 'whatsapp', 'label' => 'WhatsApp Business', 'status' => 'À intégrer'], ['code' => 'push', 'label' => 'Notifications push', 'status' => 'À intégrer']],
            ],
            'events' => collect(Notifier::EVENT_LABELS)->map(fn ($label, $code) => ['code' => $code, 'label' => $label, 'scope' => 'MVP actif'])->values()->all(),
            'variables' => Notifier::VARIABLES,
            'templates' => collect($config['templates'])->map(fn ($t, $code) => ['event' => $code, 'stats' => Notifier::smsStats($t['message'])])->values()->all(),
        ];
    }

    private function workflowPreview(int $pressingId): array
    {
        $w = $this->settings->get($pressingId, 'operations')['workshop'];

        return array_values(array_filter([
            'En attente', $w['washer_step'] ? 'Laveur' : null, 'En cours', 'Traité', $w['sorter_step'] ? 'Classeur' : null, $w['sorter_step'] ? 'Classé' : null,
        ]));
    }

    private function recentChanges(int $pressingId): array
    {
        return collect($this->settings->journal($pressingId, null, 5))
            ->map(fn ($j) => [
                'at' => $j['at'],
                'description' => trim(($j['label'] ? $j['label'].' — ' : '').$j['summary']),
                'by' => $j['by'],
                'scope' => $j['scope'] === 'agency' ? 'Agence' : 'Toutes agences',
            ])->all();
    }
}
