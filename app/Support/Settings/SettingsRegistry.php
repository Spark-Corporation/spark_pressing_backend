<?php

namespace App\Support\Settings;

use App\Models\AuditLog;
use App\Models\Pressing;
use App\Models\PressingSetting;
use App\Models\PressingSettingVersion;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Réglages par catégorie (config/settings_schema.php) : valeurs par défaut de la maquette,
 * surcharges stockées par pressing, clés reliées aux colonnes de `pressings`, journal et versions.
 */
class SettingsRegistry
{
    /** @var array<string, array> */
    private array $cache = [];

    public function categories(): array
    {
        return config('settings_schema.categories');
    }

    public function exists(string $category): bool
    {
        return isset($this->categories()[$category]);
    }

    public function get(int $pressingId, string $category, bool $withDraft = false): array
    {
        $key = $pressingId.':'.$category.':'.(int) $withDraft;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $schema = $this->schema($category);
        $row = $this->row($pressingId, $category);
        $values = self::merge($schema['defaults'], $row?->values ?? []);

        if ($bound = $schema['bound'] ?? []) {
            $pressing = Pressing::query()->find($pressingId);
            foreach ($bound as $path => $column) {
                if ($pressing && $pressing->{$column} !== null) {
                    Arr::set($values, $path, self::cast(Arr::get($schema['defaults'], $path), $pressing->{$column}));
                }
            }
        }

        if ($withDraft && $row?->draft) {
            $values = self::merge($values, $row->draft);
        }

        return $this->cache[$key] = $values;
    }

    /** Valeur ponctuelle, ex. `value($id, 'security', 'lockout.max_attempts')`. */
    public function value(?int $pressingId, string $category, string $path, mixed $fallback = null): mixed
    {
        if (! $pressingId) {
            return Arr::get($this->schema($category)['defaults'], $path, $fallback);
        }

        return Arr::get($this->get($pressingId, $category), $path, $fallback);
    }

    /**
     * Met à jour (ou met en brouillon) une catégorie ; renvoie la liste des changements.
     *
     * @return list<array{key:string,from:mixed,to:mixed}>
     */
    public function update(int $pressingId, string $category, array $input, ?User $user, bool $asDraft = false): array
    {
        $schema = $this->schema($category);
        $clean = self::normalize($schema['defaults'], $input);
        $current = $this->get($pressingId, $category, $asDraft);
        $next = self::merge($current, $clean);
        $changes = self::diff($current, $next);
        $row = $this->row($pressingId, $category) ?? new PressingSetting(['pressing_id' => $pressingId, 'category' => $category]);

        if ($asDraft) {
            $row->draft = self::merge($row->draft ?? [], $clean);
        } else {
            $bound = $schema['bound'] ?? [];
            $stored = $next;
            foreach (array_keys($bound) as $path) {
                Arr::forget($stored, $path);
            }
            $row->values = $stored;
            if ($bound) {
                $pressing = Pressing::query()->findOrFail($pressingId);
                foreach ($bound as $path => $column) {
                    $pressing->{$column} = Arr::get($next, $path);
                }
                $pressing->save();
            }
        }

        $row->updated_by = $user?->id;
        $row->save();
        $this->cache = [];

        if ($changes) {
            $this->audit($pressingId, $user, $asDraft ? 'settings.drafted' : 'settings.updated', ['category' => $category, 'changes' => $changes]);
        }

        return $changes;
    }

    /** Publie le brouillon d’une catégorie versionnée (branding, reçus) et crée une version restaurable. */
    public function publish(int $pressingId, string $category, ?User $user): array
    {
        $this->assertVersioned($category);
        $row = $this->row($pressingId, $category);
        $values = $this->get($pressingId, $category, true);

        PressingSettingVersion::query()->create([
            'pressing_id' => $pressingId,
            'category' => $category,
            'values' => $this->get($pressingId, $category),
            'published_by' => $user?->id,
        ]);

        $row ??= new PressingSetting(['pressing_id' => $pressingId, 'category' => $category]);
        $row->values = $values;
        $row->draft = null;
        $row->published_at = now();
        $row->updated_by = $user?->id;
        $row->save();
        $this->cache = [];
        $this->audit($pressingId, $user, 'settings.published', ['category' => $category]);

        return $values;
    }

    public function restore(int $pressingId, string $category, ?int $versionId, ?User $user): array
    {
        $this->assertVersioned($category);
        $values = $versionId
            ? PressingSettingVersion::query()->where('pressing_id', $pressingId)->where('category', $category)->findOrFail($versionId)->values
            : $this->schema($category)['defaults'];

        $row = $this->row($pressingId, $category) ?? new PressingSetting(['pressing_id' => $pressingId, 'category' => $category]);
        $row->draft = $values;
        $row->updated_by = $user?->id;
        $row->save();
        $this->cache = [];
        $this->audit($pressingId, $user, 'settings.restored', ['category' => $category, 'version_id' => $versionId]);

        return $values;
    }

    public function meta(int $pressingId, string $category): array
    {
        $schema = $this->schema($category);
        $row = $this->row($pressingId, $category);

        return [
            'category' => $category,
            'label' => $schema['label'],
            'versioned' => (bool) ($schema['versioned'] ?? false),
            'updated_at' => $row?->updated_at?->toIso8601String(),
            'updated_by' => $row?->updated_by ? User::query()->whereKey($row->updated_by)->value('fullname') : null,
            'published_at' => $row?->published_at?->toIso8601String(),
            'has_draft' => (bool) $row?->draft,
            'unsaved_changes' => $row?->draft ? count(self::diff($this->get($pressingId, $category), $this->get($pressingId, $category, true))) : 0,
            'versions' => ($schema['versioned'] ?? false)
                ? PressingSettingVersion::query()->where('pressing_id', $pressingId)->where('category', $category)->latest('id')->limit(10)->get()
                    ->map(fn ($v) => ['id' => $v->id, 'published_at' => $v->created_at?->toIso8601String(), 'published_by' => $v->published_by ? User::query()->whereKey($v->published_by)->value('fullname') : null])->all()
                : [],
        ];
    }

    /** Journal des modifications (« Frais livraison : 1 500 → 2 000 »). */
    public function journal(int $pressingId, ?string $category = null, int $limit = 20): array
    {
        $logs = AuditLog::query()
            ->where('pressing_id', $pressingId)
            ->where('action', 'like', 'settings.%')
            ->latest('id')
            ->limit(200)
            ->get()
            ->filter(fn (AuditLog $l) => ! $category || ($l->payload['category'] ?? null) === $category)
            ->take($limit);
        $actors = User::query()->whereIn('id', $logs->pluck('actor_id')->filter())->pluck('fullname', 'id');

        return $logs->map(function (AuditLog $l) use ($actors) {
            $changes = $l->payload['changes'] ?? [];
            $summary = collect($changes)->take(3)->map(fn ($c) => sprintf('%s : %s → %s', $c['key'], self::display($c['from']), self::display($c['to'])))->implode(' · ');

            return [
                'id' => $l->id,
                'at' => $l->created_at?->toIso8601String(),
                'action' => $l->action,
                'category' => $l->payload['category'] ?? null,
                'label' => $this->categories()[$l->payload['category'] ?? '']['label'] ?? null,
                'summary' => $summary ?: ['settings.published' => 'Publication', 'settings.restored' => 'Restauration', 'settings.drafted' => 'Brouillon enregistré'][$l->action] ?? $l->action,
                'changes' => $changes,
                'by' => $actors[$l->actor_id] ?? null,
                'scope' => $l->agency_id ? 'agency' : 'network',
            ];
        })->values()->all();
    }

    private function schema(string $category): array
    {
        if (! $this->exists($category)) {
            throw ValidationException::withMessages(['category' => "Catégorie de paramètres inconnue : {$category}."]);
        }

        return $this->categories()[$category];
    }

    private function assertVersioned(string $category): void
    {
        if (! ($this->schema($category)['versioned'] ?? false)) {
            throw ValidationException::withMessages(['category' => 'Cette catégorie ne gère pas de versions.']);
        }
    }

    private function row(int $pressingId, string $category): ?PressingSetting
    {
        return PressingSetting::query()->where('pressing_id', $pressingId)->where('category', $category)->first();
    }

    private function audit(int $pressingId, ?User $user, string $action, array $payload): void
    {
        AuditLog::query()->create([
            'pressing_id' => $pressingId,
            'actor_type' => $user ? User::class : null,
            'actor_id' => $user?->id,
            'action' => $action,
            'subject_type' => Pressing::class,
            'subject_id' => $pressingId,
            'payload' => $payload,
            'ip_address' => request()?->ip(),
        ]);
    }

    /** Fusion récursive : tableaux associatifs fusionnés, listes remplacées. */
    public static function merge(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && ! array_is_list($base[$key]) && ! array_is_list($value)) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /** Ne garde que les clés connues du schéma, typées comme la valeur par défaut. */
    public static function normalize(array $defaults, array $input): array
    {
        $out = [];
        foreach ($input as $key => $value) {
            if (! array_key_exists($key, $defaults)) {
                continue;
            }
            $default = $defaults[$key];
            if (is_array($default) && ! array_is_list($default) && $default !== []) {
                if (! is_array($value)) {
                    throw ValidationException::withMessages([$key => "La valeur de {$key} doit être un objet."]);
                }
                $out[$key] = self::normalize($default, $value);
            } else {
                $out[$key] = self::cast($default, $value, $key);
            }
        }

        return $out;
    }

    public static function cast(mixed $default, mixed $value, string $key = 'value'): mixed
    {
        return match (true) {
            is_bool($default) => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                ?? throw ValidationException::withMessages([$key => "{$key} doit être un booléen."]),
            is_int($default) => is_numeric($value) ? (int) $value : throw ValidationException::withMessages([$key => "{$key} doit être un entier."]),
            is_float($default) => is_numeric($value) ? (float) $value : throw ValidationException::withMessages([$key => "{$key} doit être un nombre."]),
            is_array($default) => is_array($value) ? array_values($value) : throw ValidationException::withMessages([$key => "{$key} doit être une liste."]),
            default => $value === null ? null : (is_scalar($value) ? (string) $value : throw ValidationException::withMessages([$key => "{$key} doit être un texte."])),
        };
    }

    /** @return list<array{key:string,from:mixed,to:mixed}> */
    public static function diff(array $before, array $after): array
    {
        $a = Arr::dot($before);
        $b = Arr::dot($after);
        $changes = [];
        foreach ($b as $key => $value) {
            if (! array_key_exists($key, $a) || $a[$key] !== $value) {
                $changes[] = ['key' => $key, 'from' => $a[$key] ?? null, 'to' => $value];
            }
        }

        return $changes;
    }

    private static function display(mixed $v): string
    {
        return match (true) {
            is_bool($v) => $v ? 'activé' : 'désactivé',
            is_int($v) => number_format($v, 0, ',', ' '),
            $v === null => '—',
            is_array($v) => '['.count($v).']',
            default => (string) $v,
        };
    }
}
