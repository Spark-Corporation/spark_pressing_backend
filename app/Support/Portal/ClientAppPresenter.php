<?php

namespace App\Support\Portal;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Deposit;
use App\Models\LoyaltyLedger;
use App\Models\NotificationLog;
use App\Support\Deposits\DepositPresenter;
use App\Support\Notifications\Notifier;
use App\Support\Settings\SettingsRegistry;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\URL;

/** Écrans Figma « 14 — Mobile client » : accueil, dépôts, suivi, fidélité, profil. */
class ClientAppPresenter
{
    /** Étapes du suivi client (états atelier). */
    public const STEPS = [
        Deposit::ETAT_WAITING => ['label' => 'Reçu', 'title' => 'En attente', 'detail' => 'Dépôt enregistré'],
        Deposit::ETAT_IN_PROGRESS => ['label' => 'En cours', 'title' => 'En cours', 'detail' => 'Lavage en cours'],
        Deposit::ETAT_TREATED => ['label' => 'Traité', 'title' => 'Traité', 'detail' => 'Contrôle qualité'],
        Deposit::ETAT_CLASSED => ['label' => 'Classé', 'title' => 'Classé', 'detail' => 'Prêt au retrait en agence'],
    ];

    public function __construct(private readonly SettingsRegistry $settings) {}

    public function home(Client $client): array
    {
        $client->loadMissing('agency:id,name,contact,address');
        $open = Deposit::query()->withoutGlobalScopes()->where('client_id', $client->id)->where('status', true)
            ->withSum('units as pieces', 'quantity')->orderByDesc('deposit_date')->get();
        $active = $open->first();
        $next = $open->whereNotNull('retrieve_date')->sortBy('retrieve_date')->first();
        $notification = NotificationLog::query()->where('client_id', $client->id)->where('is_test', false)->latest('id')->first();

        return [
            'greeting' => 'Bonjour '.$this->firstName($client),
            'subtitle' => $active ? 'Votre linge avance bien.' : 'Aucun dépôt en cours.',
            'initials' => self::initials($client->fullname),
            'agency' => $client->agency ? ['id' => $client->agency->id, 'name' => $client->agency->name] : null,
            'active_deposit' => $active ? [
                'id' => $active->id,
                'code' => $active->code,
                'express' => (bool) $active->is_express,
                'summary' => sprintf('%d pièces · confiées le %s', (int) $active->pieces, $active->deposit_date?->locale('fr')->isoFormat('D MMMM')),
                'steps' => $this->steps($active),
                'status_line' => $this->statusLine($active),
            ] : null,
            'tiles' => [
                'next_retrieval' => $next ? ['at' => $next->retrieve_date->toIso8601String(), 'label' => self::dayLabel($next->retrieve_date)] : null,
                'left_to_pay' => (int) $open->sum('left_to_pay'),
                'loyalty_points' => (int) $client->loyalty_points,
            ],
            'quick_actions' => [
                'track' => $active ? "/api/v1/client/app/deposits/{$active->id}" : null,
                'ticket_url' => $active ? $this->ticketUrl($active) : null,
                'call' => $client->agency?->contact,
                'directions' => $client->agency?->address,
            ],
            'latest_notification' => $notification ? [
                'title' => $this->notificationTitle($notification->event),
                'deposit_code' => $notification->deposit_id ? Deposit::query()->withoutGlobalScopes()->whereKey($notification->deposit_id)->value('code') : null,
                'at' => $notification->created_at?->toIso8601String(),
                'ago' => $notification->created_at?->locale('fr')->diffForHumans(),
            ] : null,
        ];
    }

    public function depositCard(Deposit $d): array
    {
        $status = match (true) {
            $d->retrieved_at !== null || ! $d->status => ['code' => 'retrieved', 'label' => 'Retiré'],
            in_array($d->etat, [Deposit::ETAT_TREATED, Deposit::ETAT_CLASSED], true) => ['code' => 'ready', 'label' => Deposit::etatLabel($d->etat) ?? 'Prêt'],
            default => ['code' => 'in_progress', 'label' => 'En cours'],
        };

        return [
            'id' => $d->id,
            'code' => $d->code,
            'status' => $status['code'],
            'status_label' => $status['label'],
            'deposited_at' => $d->deposit_date?->toIso8601String(),
            'total' => (int) $d->total,
            'payment_label' => (int) $d->left_to_pay === 0 ? 'Payé' : ((int) $d->advanced > 0 ? 'Acompte' : 'À payer'),
            'retrieval_label' => match ($status['code']) {
                'retrieved' => 'Retiré le '.($d->retrieved_at ?? $d->updated_at)?->locale('fr')->isoFormat('D MMM'),
                'ready' => 'Prêt au retrait',
                default => $d->retrieve_date ? $d->retrieve_date->locale('fr')->isoFormat('D MMM · HH:mm') : null,
            },
        ];
    }

    /** « Suivi du dépôt ». */
    public function tracking(Deposit $deposit, DepositPresenter $presenter): array
    {
        $detail = $presenter->detail($deposit);
        $agency = $deposit->agency;

        return [
            'id' => $deposit->id,
            'code' => $deposit->code,
            'express' => (bool) $deposit->is_express,
            'deposited_label' => 'Déposé le '.$deposit->deposit_date?->locale('fr')->isoFormat('D MMMM [à] HH:mm'),
            'retrieve_date' => $deposit->retrieve_date?->toIso8601String(),
            'retrieve_label' => $deposit->retrieve_date ? ucfirst($deposit->retrieve_date->locale('fr')->isoFormat('dddd D MMM · HH:mm')) : null,
            'progress' => $this->steps($deposit, true),
            'articles' => [
                'pieces' => (int) $deposit->units->sum('quantity'),
                'lines' => $deposit->units->map(fn ($u) => ['quantity' => (int) $u->quantity, 'label' => $u->designation ?: 'Article', 'amount' => (int) $u->line_total])->values()->all(),
            ],
            'payment' => [
                'label' => (int) $deposit->left_to_pay === 0 ? 'Payé' : ((int) $deposit->advanced > 0 ? 'Acompte' : 'À payer'),
                'total' => (int) $deposit->total,
                'paid' => (int) $deposit->total - (int) $deposit->left_to_pay,
                'left_to_pay' => (int) $deposit->left_to_pay,
                'ticket_url' => $this->ticketUrl($deposit),
            ],
            'agency' => $agency ? ['name' => $agency->name, 'phone' => $agency->contact, 'address' => $agency->address] : null,
            'loyalty_points' => (int) $deposit->client?->loyalty_points,
            'totals' => $detail['totals'],
        ];
    }

    /** « Ma fidélité » : solde, niveau (points ou dépenses 12 mois selon les réglages), récompense, historique 90 j, règles. */
    public function loyalty(Client $client): array
    {
        $config = $this->settings->get((int) $client->pressing_id, 'loyalty');
        $program = $config['program'];
        $levels = collect($config['tiers']['levels'])->sortBy('min')->values();
        $basis = $config['tiers']['basis'];
        $measure = $basis === 'spend_12m'
            ? (int) Deposit::query()->withoutGlobalScopes()->where('client_id', $client->id)->where('deposit_date', '>=', now()->subYear())->sum('total')
            : (int) $client->loyalty_points;
        $current = $levels->filter(fn ($l) => $measure >= $l['min'])->last() ?? $levels->first();
        $next = $levels->first(fn ($l) => $l['min'] > $measure);
        $points = (int) $client->loyalty_points;
        $conversionPoints = max(1, (int) $program['conversion_points']);
        $minimum = (int) $program['minimum_points'];
        $target = max($minimum, (int) (ceil(($points + 1) / 1000) * 1000));
        $missing = max(0, $target - $points);

        return [
            'points' => $points,
            'tier' => ['code' => $current['code'], 'label' => $current['label'], 'multiplier' => $current['multiplier']],
            'convertible_value' => $points >= $minimum ? (int) floor($points / $conversionPoints) * (int) $program['conversion_value'] : 0,
            'progress' => [
                'basis' => $basis,
                'current' => $measure,
                'current_label' => $current['label'],
                'next' => $next ? ['code' => $next['code'], 'label' => $next['label'], 'min' => $next['min']] : null,
                'remaining' => $next ? $next['min'] - $measure : 0,
                'percent' => $next ? (int) min(100, round($measure / max(1, $next['min']) * 100)) : 100,
                'label' => $next ? sprintf('Encore %s %s pour le niveau %s', number_format($next['min'] - $measure, 0, ',', ' '), $basis === 'spend_12m' ? 'FCFA de dépenses sur 12 mois' : 'points', $next['label']) : 'Niveau maximal atteint',
            ],
            'benefits' => array_values(array_filter([
                $current['multiplier'] > 1 ? sprintf('+%d %% de points sur chaque dépôt', round(($current['multiplier'] - 1) * 100)) : null,
                $current['benefit'] ?? null,
            ])),
            'next_reward' => [
                'points' => $target,
                'value' => (int) floor($target / $conversionPoints) * (int) $program['conversion_value'],
                'missing_points' => $missing,
                'percent' => (int) min(100, round($points / max(1, $target) * 100)),
                'estimated_spend' => $missing ? (int) ceil($missing / max(1, $program['earn_points_per_1000'] * $current['multiplier']) * 1000) : 0,
            ],
            'history' => LoyaltyLedger::query()->withoutGlobalScopes()->where('client_id', $client->id)->where('created_at', '>=', now()->subDays(90))
                ->latest('id')->limit(50)->get()->map(fn (LoyaltyLedger $l) => [
                    'label' => $l->reason ?: ($l->type === 'redeem' ? 'Remise fidélité' : 'Points gagnés'),
                    'type' => $l->type,
                    'points' => $l->type === 'redeem' || $l->type === 'expire' ? -abs((int) $l->points) : (int) $l->points,
                    'at' => $l->created_at?->toIso8601String(),
                ])->values()->all(),
            'rules' => array_values(array_filter([
                sprintf('%d points gagnés par tranche de 1 000 FCFA payés.', $program['earn_points_per_1000']),
                sprintf('Conversion dès %s points : %s points valent %s FCFA.', number_format($minimum, 0, ',', ' '), number_format($conversionPoints, 0, ',', ' '), number_format($program['conversion_value'], 0, ',', ' ')),
                $program['expiration_months'] ? sprintf('Les points expirent %d mois après leur date d’acquisition.', $program['expiration_months']) : null,
                $program['stack_with_promotions'] ? null : 'Une remise fidélité ne se cumule pas avec un code promotionnel.',
            ])),
        ];
    }

    public function profile(Client $client): array
    {
        $client->loadMissing('agency:id,name,address');

        return [
            'identity' => [
                'fullname' => $client->fullname,
                'initials' => self::initials($client->fullname),
                'client_since' => $client->created_at?->toIso8601String(),
                'client_since_label' => 'Client depuis '.$client->created_at?->locale('fr')->isoFormat('MMMM YYYY'),
                'verified' => $client->phone_verified_at !== null,
                'phone_number' => $client->phone_number,
                'phone_verified' => $client->phone_verified_at !== null,
                'email' => $client->email,
                'address' => $client->address,
                'preferred_agency' => $client->agency ? ['id' => $client->agency->id, 'name' => $client->agency->name, 'address' => $client->agency->address] : null,
            ],
            'communication' => [
                'sms' => (bool) $client->consent_sms,
                'email' => (bool) $client->consent_email,
                'personalized_offers' => (bool) $client->consent_marketing,
            ],
            'password' => [
                'changed_at' => $client->password_changed_at?->toIso8601String(),
                'changed_label' => $client->password_changed_at ? 'dernière modification '.$client->password_changed_at->locale('fr')->diffForHumans() : null,
                'rules' => ['min_length' => 8, 'uppercase' => true, 'digit' => true],
            ],
            'sessions' => $client->tokens()->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->latest('last_used_at')->get()
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'device' => self::device((string) $t->user_agent),
                    'ip_address' => $t->ip_address,
                    'last_used_at' => ($t->last_used_at ?? $t->created_at)?->toIso8601String(),
                    'current' => (int) $t->id === (int) $client->currentAccessToken()?->id,
                ])->values()->all(),
        ];
    }

    /** Étapes du dépôt avec l’heure de passage (journal atelier) ; `$detailed` pour l’écran de suivi. */
    private function steps(Deposit $deposit, bool $detailed = false): array
    {
        $logs = AuditLog::query()->where('subject_type', Deposit::class)->where('subject_id', $deposit->id)->where('action', 'deposit.workshop')->orderBy('id')->get()
            ->mapWithKeys(fn ($l) => [$l->payload['to'] ?? '' => $l->created_at]);
        $order = array_keys(self::STEPS);
        $currentIndex = array_search($deposit->etat, $order, true);

        return collect(self::STEPS)->map(function ($step, $etat) use ($order, $currentIndex, $logs, $deposit, $detailed) {
            $index = array_search($etat, $order, true);
            $at = $etat === Deposit::ETAT_WAITING ? $deposit->deposit_date : ($logs[$etat] ?? null);
            $state = match (true) {
                $deposit->retrieved_at !== null || $index < $currentIndex => 'done',
                $index === $currentIndex => $etat === Deposit::ETAT_CLASSED ? 'done' : 'current',
                default => 'upcoming',
            };

            return array_filter([
                'etat' => $etat,
                'label' => $detailed ? $step['title'] : $step['label'],
                'state' => $state,
                'at' => $at?->toIso8601String(),
                'detail' => $detailed ? match ($state) {
                    'done' => $step['detail'].($at ? ' · '.$at->format('H:i') : ''),
                    'current' => $step['detail'].($at ? ' · depuis '.$at->format('H:i') : ''),
                    default => $step['detail'].' à venir',
                } : null,
            ], fn ($v) => $v !== null);
        })->values()->all();
    }

    private function statusLine(Deposit $deposit): string
    {
        $step = self::STEPS[$deposit->etat]['detail'] ?? 'En traitement';

        return $deposit->retrieve_date ? $step.' · prêt '.mb_strtolower(self::dayLabel($deposit->retrieve_date)) : $step;
    }

    private function ticketUrl(Deposit $deposit): string
    {
        return URL::temporarySignedRoute('v1.public.deposits.document', now()->addDays(7), ['deposit' => $deposit->id, 'type' => 'ticket', 'format' => 'pdf']);
    }

    private function notificationTitle(string $event): string
    {
        return match ($event) {
            'in_progress' => 'Votre dépôt est passé au lavage',
            'ready' => 'Votre dépôt est prêt au retrait',
            'deposit_created' => 'Votre dépôt est enregistré',
            'payment_received' => 'Paiement reçu',
            'retrieved' => 'Retrait effectué',
            default => Notifier::EVENT_LABELS[$event] ?? 'Notification',
        };
    }

    private function firstName(Client $client): string
    {
        return $client->first_name ?: (string) strtok((string) $client->fullname, ' ');
    }

    public static function dayLabel(CarbonInterface $at): string
    {
        return match (true) {
            $at->isToday() => 'Aujourd’hui · '.$at->format('H:i'),
            $at->isTomorrow() => 'Demain · '.$at->format('H:i'),
            default => ucfirst($at->locale('fr')->isoFormat('ddd D MMM · HH:mm')),
        };
    }

    public static function initials(?string $name): string
    {
        return collect(explode(' ', (string) $name))->filter()->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->take(2)->implode('');
    }

    public static function device(string $ua): string
    {
        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS') => 'Mac',
            default => null,
        };
        $browser = match (true) {
            str_contains($ua, 'Chrome') => 'Chrome',
            str_contains($ua, 'Firefox') => 'Firefox',
            str_contains($ua, 'Safari') => 'Safari',
            default => null,
        };

        return $os && $browser && $os !== 'iPhone' ? "{$browser} sur {$os}" : ($os ?? $browser ?? 'Appareil inconnu');
    }
}
