<?php

namespace App\Actions\License;

use App\Models\AuditLog;
use App\Models\License;
use App\Models\LicensePayment;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RenewLicense
{
    /**
     * Enregistre le règlement d’une période et prolonge la licence.
     * Le paiement reste « pending » tant qu’il n’est pas validé (sauf `mark_paid`).
     */
    public function handle(?Authenticatable $actor, License $license, array $data, ?UploadedFile $proof = null): LicensePayment
    {
        $plan = $data['plan'] ?? $license->plan;
        $periodicity = $data['periodicity'];
        $months = (int) config("licensing.periodicities.{$periodicity}.months");
        $price = config("licensing.plans.{$plan}.prices.{$periodicity}");

        if ($price === null) {
            throw ValidationException::withMessages(['plan' => "Aucun tarif {$periodicity} pour le plan {$plan}."]);
        }

        $start = isset($data['effective_at'])
            ? CarbonImmutable::parse($data['effective_at'])->startOfDay()
            : ($license->expires_at?->isFuture()
                ? CarbonImmutable::parse($license->expires_at)->addDay()->startOfDay()
                : CarbonImmutable::now()->startOfDay());
        $end = $start->addMonths($months);
        $paid = (bool) ($data['mark_paid'] ?? false);

        return DB::transaction(function () use ($actor, $license, $data, $proof, $plan, $periodicity, $price, $start, $end, $paid) {
            $payment = LicensePayment::query()->create([
                'license_id' => $license->id,
                'pressing_id' => $license->pressing_id,
                'plan' => $plan,
                'periodicity' => $periodicity,
                'amount' => (int) $price,
                'taxes' => (int) round($price * (float) config('licensing.tax_rate')),
                'currency' => config('licensing.currency'),
                'payment_method' => $data['payment_method'],
                'reference' => $data['reference'] ?? null,
                'status' => $paid ? 'paid' : 'pending',
                'paid_at' => $paid ? now() : null,
                'period_start' => $start,
                'period_end' => $end,
                'recorded_by_type' => $actor ? $actor::class : null,
                'recorded_by_id' => $actor?->getAuthIdentifier(),
            ]);

            if ($proof) {
                $payment->update([
                    'proof_path' => $proof->store("pressings/{$license->pressing_id}/licenses/{$license->id}"),
                ]);
            }

            $updates = [
                'plan' => $plan,
                'periodicity' => $periodicity,
                'is_activated' => true,
                'activated_at' => $license->activated_at ?? now(),
                'expires_at' => $license->expires_at && $license->expires_at->gt($end) ? $license->expires_at : $end,
            ];
            if (array_key_exists('auto_renew', $data)) {
                $updates['auto_renew'] = (bool) $data['auto_renew'];
            }
            if ($plan !== $license->plan && ($seats = config("licensing.plans.{$plan}.limits.users"))) {
                $updates['seats'] = $seats;
            }
            if ($start->lte(now())) {
                $updates['period_started_at'] = $start;
            } elseif (! $license->period_started_at) {
                $updates['period_started_at'] = $updates['activated_at'];
            }

            $license->update($updates);

            AuditLog::record('license.renewed', $license, [
                'payment_id' => $payment->id,
                'plan' => $plan,
                'periodicity' => $periodicity,
                'amount' => $payment->total(),
                'reference' => $payment->reference,
            ]);

            return $payment;
        });
    }

    public function validatePayment(LicensePayment $payment): LicensePayment
    {
        $payment->update(['status' => 'paid', 'paid_at' => $payment->paid_at ?? now()]);

        AuditLog::record('license.payment_validated', $payment->license, ['payment_id' => $payment->id]);

        return $payment;
    }
}
