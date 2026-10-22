<?php

namespace App\Support\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Passerelle SMS. Aucun fournisseur n’est branché : les messages sont journalisés
 * sur le canal `services.sms.log_channel` en attendant l’intégration opérateur.
 */
class SmsSender
{
    public function send(string $phone, string $message): void
    {
        Log::channel(config('services.sms.log_channel', config('logging.default')))
            ->info('sms.outgoing', ['to' => $phone, 'message' => $message]);
    }

    public static function mask(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone);

        return '•••• '.substr($digits, -2);
    }
}
