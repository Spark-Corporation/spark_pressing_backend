<?php

namespace App\Support\Notifications;

use App\Models\Deposit;
use App\Models\NotificationLog;
use App\Support\Settings\SettingsRegistry;
use App\Support\Sms\SmsSender;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Notifications automatiques (Figma 09 « Notifications ») : événements × canaux, modèles à variables,
 * horaires silencieux, consentement marketing, journal des envois.
 */
class Notifier
{
    public const EVENT_LABELS = [
        'deposit_created' => 'Dépôt enregistré',
        'in_progress' => 'En traitement',
        'ready' => 'Prêt au retrait',
        'late' => 'Retard',
        'retrieved' => 'Retrait effectué',
        'payment_received' => 'Paiement reçu',
        'loyalty' => 'Fidélité',
    ];

    public const VARIABLES = ['{{client.prenom}}', '{{client.nom}}', '{{client.points}}', '{{depot.numero}}', '{{depot.solde}}', '{{depot.date_limite}}', '{{agence.nom}}', '{{pressing.nom}}'];

    /** Confirmations envoyées même pendant les horaires silencieux. */
    private const IMMEDIATE = ['deposit_created', 'payment_received', 'retrieved'];

    public function __construct(private readonly SettingsRegistry $settings, private readonly SmsSender $sms) {}

    /**
     * Envoie l’événement sur les canaux activés (plus `$force`, ex. case « Prévenir par SMS »).
     * Ne lève jamais d’exception : un échec d’envoi est journalisé.
     *
     * @return list<NotificationLog>
     */
    public function dispatch(string $event, Deposit $deposit, array $force = []): array
    {
        $deposit->loadMissing(['client', 'agency:id,name', 'pressing:id,name']);
        $config = $this->settings->get($deposit->pressing_id, 'notifications');
        $channels = collect($config['events'][$event] ?? [])->filter()->keys()->merge($force)->unique();
        $client = $deposit->client;

        if (! $client || ($event === 'loyalty' && $config['compliance']['require_marketing_consent'] && ! $client->consent_sms && ! $client->consent_email)) {
            return [];
        }

        $message = $this->render($config['templates'][$event]['message'] ?? '', $deposit);
        $logs = [];
        foreach ($channels as $channel) {
            $recipient = $channel === 'sms' ? $client->phone_number : $client->email;
            if (! $recipient) {
                continue;
            }
            $status = $this->inQuietHours($config['quiet_hours']) && ! in_array($event, self::IMMEDIATE, true) && ! in_array($channel, $force, true)
                ? 'scheduled'
                : null;
            $logs[] = $this->send($deposit->pressing_id, $event, $channel, $recipient, $message, [
                'agency_id' => $deposit->agency_id, 'client_id' => $client->id, 'deposit_id' => $deposit->id,
            ], $status);
        }

        return $logs;
    }

    /** « Envoyer un test » avec un dépôt d’exemple. */
    public function test(int $pressingId, string $event, string $channel, string $recipient, ?string $message = null): NotificationLog
    {
        $template = $message ?? ($this->settings->get($pressingId, 'notifications')['templates'][$event]['message'] ?? '');

        return $this->send($pressingId, $event, $channel, $recipient, $this->render($template, null), ['is_test' => true]);
    }

    /** Remplace les variables `{{…}}` ; sans dépôt, utilise les valeurs de l’aperçu Figma. */
    public function render(string $template, ?Deposit $deposit): string
    {
        $client = $deposit?->client;
        $values = $deposit ? [
            '{{client.prenom}}' => $client?->first_name ?: strtok((string) $client?->fullname, ' '),
            '{{client.nom}}' => $client?->fullname,
            '{{client.points}}' => number_format((int) $client?->loyalty_points, 0, ',', ' '),
            '{{depot.numero}}' => $deposit->code,
            '{{depot.solde}}' => number_format((int) $deposit->left_to_pay, 0, ',', ' '),
            '{{depot.date_limite}}' => $deposit->retrieve_date?->format('d/m/Y à H\hi') ?? '—',
            '{{agence.nom}}' => $deposit->agency?->name,
            '{{pressing.nom}}' => $deposit->pressing?->name,
        ] : [
            '{{client.prenom}}' => 'Aminata', '{{client.nom}}' => 'Aminata Koné', '{{client.points}}' => '1 240',
            '{{depot.numero}}' => 'DP-2026-2843', '{{depot.solde}}' => '9 500', '{{depot.date_limite}}' => '28/09/2026 à 17h00',
            '{{agence.nom}}' => 'Bingerville', '{{pressing.nom}}' => 'SPARK PRESSING',
        ];

        return strtr($template, array_map(fn ($v) => (string) $v, $values));
    }

    /** « 159 / 320 caractères · estimé 1 SMS ». */
    public static function smsStats(string $message): array
    {
        $length = mb_strlen($message);

        return ['length' => $length, 'max' => 320, 'segments' => max(1, (int) ceil($length / 160))];
    }

    private function send(int $pressingId, string $event, string $channel, string $recipient, string $message, array $attributes, ?string $status = null): NotificationLog
    {
        $error = null;
        if (! $status) {
            try {
                if ($channel === 'sms') {
                    $this->sms->send($recipient, $message);
                } else {
                    Mail::raw($message, fn ($mail) => $mail->to($recipient)->subject(self::EVENT_LABELS[$event] ?? 'Notification'));
                }
                $status = 'sent';
            } catch (Throwable $e) {
                [$status, $error] = ['failed', mb_substr($e->getMessage(), 0, 250)];
            }
        }

        return NotificationLog::query()->create([
            ...$attributes,
            'pressing_id' => $pressingId,
            'event' => $event,
            'channel' => $channel,
            'recipient' => $recipient,
            'message' => $message,
            'status' => $status,
            'error' => $error,
        ]);
    }

    private function inQuietHours(array $quiet): bool
    {
        if (! ($quiet['enabled'] ?? false)) {
            return false;
        }
        $now = now()->format('H:i');

        return $quiet['start'] > $quiet['end']
            ? $now >= $quiet['start'] || $now < $quiet['end']
            : $now >= $quiet['start'] && $now < $quiet['end'];
    }
}
