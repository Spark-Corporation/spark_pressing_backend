<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** État du linge / défaut constaté à la réception (« Taché », « Déchiré »…), configurable par pressing. */
class LaundryStatus extends Model
{
    use BelongsToTenant, HasFactory;

    /** Descriptions par défaut des états de la maquette. */
    public const DEFAULT_DESCRIPTIONS = [
        'stain' => 'Tache visible à la réception',
        'torn' => 'Déchirure du tissu',
        'broken_button' => 'Bouton manquant ou abîmé',
        'hole' => 'Perforation constatée',
        'faded' => 'Perte ou transfert de couleur',
        'other' => 'Défaut à préciser en interne',
    ];

    protected $fillable = [
        'pressing_id', 'title', 'status', 'code', 'description', 'sort_order',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    public function tenantScopesToAgency(): bool
    {
        return false;
    }

    /** Crée les états par défaut (Deposit::DEFECTS) pour un pressing qui n’en a pas encore. */
    public static function ensureDefaults(int $pressingId): void
    {
        $existing = static::query()->withoutGlobalScopes()->where('pressing_id', $pressingId)->whereNotNull('code')->pluck('code')->all();
        $order = 0;
        foreach (Deposit::DEFECTS as $code => $label) {
            $order++;
            if (! in_array($code, $existing, true)) {
                static::query()->withoutGlobalScopes()->create([
                    'pressing_id' => $pressingId, 'code' => $code, 'title' => $label,
                    'description' => self::DEFAULT_DESCRIPTIONS[$code] ?? null, 'status' => true, 'sort_order' => $order,
                ]);
            }
        }
    }

    /** @return array<string,string> code => libellé (actifs seulement si `$activeOnly`). */
    public static function catalog(?int $pressingId, bool $activeOnly = true): array
    {
        if (! $pressingId) {
            return Deposit::DEFECTS;
        }
        static::ensureDefaults($pressingId);

        return static::query()->withoutGlobalScopes()->where('pressing_id', $pressingId)->whereNotNull('code')
            ->when($activeOnly, fn ($q) => $q->where('status', true))
            ->orderBy('sort_order')->orderBy('id')->pluck('title', 'code')->all();
    }

    public static function codeFor(string $title): string
    {
        return Str::limit(Str::slug($title, '_'), 40, '');
    }
}
