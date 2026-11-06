<?php

namespace App\Support\Loyalty;

use App\Models\Client;
use App\Models\Pressing;

/**
 * Barème pressing : quota de points pour 1 FCFA ou pour 5 FCFA, choisi par client.
 * Sans choix client, le couple historique (seuil de points = valeur FCFA) reste appliqué.
 */
class PointRate
{
    public function __construct(
        public readonly int $quota,
        public readonly int $unit,
        public readonly string $mode,
    ) {}

    public static function for(?Client $client, ?Pressing $pressing): self
    {
        $unit = (int) ($client?->loyalty_fcfa_unit ?? 0);
        if ($pressing && ! array_key_exists('loyalty_points_for_1', $pressing->getAttributes())) {
            $pressing = Pressing::query()->find($pressing->id) ?? $pressing;
        }
        if (in_array($unit, [1, 5], true)) {
            $column = $unit === 5 ? 'loyalty_points_for_5' : 'loyalty_points_for_1';
            $stored = (int) ($pressing?->{$column} ?? 0);
            $fallback = (int) config('settings_schema.categories.loyalty.defaults.program.'.($unit === 5 ? 'points_for_5_fcfa' : 'points_for_1_fcfa'), 1);

            return new self(max(1, $stored > 0 ? $stored : $fallback), $unit, 'unit');
        }

        return new self(
            max(1, (int) ($pressing?->loyalty_redeem_threshold ?? 100)),
            (int) ($pressing?->loyalty_redeem_value ?? 0),
            'legacy',
        );
    }

    /** Valeur affichée du solde (le reliquat de points compte au prorata). */
    public function valueOf(int $points): int
    {
        if ($points <= 0 || $this->unit <= 0) {
            return 0;
        }

        return (int) floor($points * $this->unit / $this->quota);
    }

    /** Remise effectivement créditée : uniquement les blocs complets du quota. */
    public function discountFor(int $points): int
    {
        if ($points <= 0 || $this->unit <= 0) {
            return 0;
        }

        return intdiv($points, $this->quota) * $this->unit;
    }

    public function label(): string
    {
        return sprintf('%s points = %s FCFA', number_format($this->quota, 0, ',', ' '), number_format($this->unit, 0, ',', ' '));
    }
}
