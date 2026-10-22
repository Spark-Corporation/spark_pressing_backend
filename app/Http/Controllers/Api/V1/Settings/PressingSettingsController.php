<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\PosDevice;
use App\Support\Settings\SettingsHub;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Knuckles\Scribe\Attributes\Group;

#[Group('Paramètres')]
class PressingSettingsController extends Controller
{
    public function __construct(private readonly SettingsRegistry $settings, private readonly SettingsHub $hub) {}

    /** Hub « Paramètres » : 16 cartes groupées, état global, dernières modifications (`group`, `q`). */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'group' => ['nullable', 'in:structure,operations,finance,platform'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->ok($this->hub->overview($request->user()->pressing_id, $data['group'] ?? null, $data['q'] ?? null));
    }

    /** Valeurs d’une catégorie (brouillon inclus pour branding/reçus) + contexte d’écran. */
    public function show(Request $request, string $category): JsonResponse
    {
        $pressingId = $request->user()->pressing_id;
        $meta = $this->settings->meta($pressingId, $category);

        return $this->ok([
            ...$meta,
            'values' => $this->settings->get($pressingId, $category, $meta['versioned']),
            'published' => $meta['versioned'] ? $this->settings->get($pressingId, $category) : null,
            'context' => $this->hub->context($pressingId, $category),
            'journal' => $this->settings->journal($pressingId, $category, 5),
        ]);
    }

    /**
     * Enregistre une catégorie (fusion partielle, clés inconnues ignorées, types contrôlés).
     * Pour branding/reçus, les changements restent en brouillon jusqu’à `publish` (`draft=false` pour forcer).
     */
    public function update(Request $request, string $category): JsonResponse
    {
        $data = $request->validate(['values' => ['required', 'array'], 'draft' => ['nullable', 'boolean']]);
        $pressingId = $request->user()->pressing_id;
        $versioned = $this->settings->meta($pressingId, $category)['versioned'];
        $changes = $this->settings->update($pressingId, $category, $data['values'], $request->user(), $data['draft'] ?? $versioned);

        return $this->ok([
            ...$this->settings->meta($pressingId, $category),
            'values' => $this->settings->get($pressingId, $category, $versioned),
            'changes' => $changes,
        ]);
    }

    public function publish(Request $request, string $category): JsonResponse
    {
        $pressingId = $request->user()->pressing_id;
        $values = $this->settings->publish($pressingId, $category, $request->user());

        return $this->ok([
            ...$this->settings->meta($pressingId, $category),
            'values' => $values,
            'impact' => ['agencies' => Agency::query()->where('pressing_id', $pressingId)->where('status', true)->count()],
        ]);
    }

    /** « Restaurer par défaut » (sans `version_id`) ou une version publiée : charge le brouillon. */
    public function restore(Request $request, string $category): JsonResponse
    {
        $data = $request->validate(['version_id' => ['nullable', 'integer']]);
        $pressingId = $request->user()->pressing_id;
        $values = $this->settings->restore($pressingId, $category, $data['version_id'] ?? null, $request->user());

        return $this->ok([...$this->settings->meta($pressingId, $category), 'values' => $values]);
    }

    /** « Journal des modifications » (toutes catégories ou `category`). */
    public function journal(Request $request): JsonResponse
    {
        $data = $request->validate(['category' => ['nullable', 'string'], 'limit' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return $this->ok($this->settings->journal($request->user()->pressing_id, $data['category'] ?? null, $data['limit'] ?? 30));
    }

    /** Appareils de caisse autorisés (Sécurité, Mode hors ligne). */
    public function devices(Request $request): JsonResponse
    {
        $data = $request->validate(['agency_id' => ['nullable', 'integer'], 'status' => ['nullable', 'in:authorized,pending,revoked,expired']]);
        $devices = PosDevice::query()->with(['agency:id,name', 'responsible:id,fullname'])
            ->when($data['agency_id'] ?? null, fn ($q, $id) => $q->where('agency_id', $id))
            ->orderBy('code')->get()
            ->filter(fn (PosDevice $d) => ! isset($data['status']) || $d->effectiveStatus() === $data['status']);

        return $this->ok($devices->map(fn (PosDevice $d) => SettingsHub::device($d))->values()->all(), [
            'summary' => collect(PosDevice::STATUS_LABELS)->map(fn ($label, $code) => $devices->filter(fn ($d) => $d->effectiveStatus() === $code)->count())->all(),
        ]);
    }

    /** « Autoriser l’appareil » : créé autorisé pour `security.devices.validity_days` (ou en attente si `pending`). */
    public function storeDevice(Request $request): JsonResponse
    {
        $pressingId = $request->user()->pressing_id;
        $data = $request->validate([
            'agency_id' => ['required', 'integer', 'exists:agencies,id'],
            'code' => ['required', 'string', 'max:40'],
            'type' => ['nullable', 'in:pos,tablet,workshop'],
            'label' => ['nullable', 'string', 'max:191'],
            'responsible_id' => ['nullable', 'integer', 'exists:users,id'],
            'offline_enabled' => ['nullable', 'boolean'],
            'validity_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'pending' => ['nullable', 'boolean'],
        ]);
        abort_unless((int) Agency::query()->whereKey($data['agency_id'])->value('pressing_id') === (int) $pressingId, 403);
        if (PosDevice::query()->where('code', strtoupper($data['code']))->exists()) {
            throw ValidationException::withMessages(['code' => 'Ce code appareil existe déjà.']);
        }

        $device = new PosDevice([
            ...collect($data)->except(['validity_days', 'pending'])->all(),
            'pressing_id' => $pressingId,
            'code' => strtoupper($data['code']),
            'type' => $data['type'] ?? 'pos',
            'status' => 'pending',
        ]);
        if (! ($data['pending'] ?? false)) {
            $this->grant($device, $request, $data['validity_days'] ?? null);
        }
        $device->save();
        AuditLog::record('device.created', $device, ['code' => $device->code, 'status' => $device->status]);

        return $this->created(SettingsHub::device($device->load(['agency:id,name', 'responsible:id,fullname'])));
    }

    public function updateDevice(Request $request, PosDevice $device): JsonResponse
    {
        $device->update($request->validate([
            'label' => ['nullable', 'string', 'max:191'],
            'responsible_id' => ['nullable', 'integer', 'exists:users,id'],
            'offline_enabled' => ['sometimes', 'boolean'],
            'type' => ['sometimes', 'in:pos,tablet,workshop'],
        ]));
        AuditLog::record('device.updated', $device, $device->getChanges());

        return $this->ok(SettingsHub::device($device->load(['agency:id,name', 'responsible:id,fullname'])));
    }

    public function approveDevice(Request $request, PosDevice $device): JsonResponse
    {
        $data = $request->validate(['validity_days' => ['nullable', 'integer', 'min:1', 'max:365']]);
        $this->grant($device, $request, $data['validity_days'] ?? null);
        $device->save();
        AuditLog::record('device.authorized', $device, ['code' => $device->code, 'expires_at' => $device->expires_at?->toIso8601String()]);

        return $this->ok(SettingsHub::device($device->load(['agency:id,name', 'responsible:id,fullname'])));
    }

    public function revokeDevice(Request $request, PosDevice $device): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $device->update(['status' => 'revoked', 'offline_enabled' => false]);
        AuditLog::record('device.revoked', $device, ['code' => $device->code, 'reason' => $data['reason'] ?? null]);

        return $this->ok(SettingsHub::device($device->load(['agency:id,name', 'responsible:id,fullname'])));
    }

    private function grant(PosDevice $device, Request $request, ?int $days): void
    {
        $days ??= (int) $this->settings->value($request->user()->pressing_id, 'security', 'devices.validity_days', 90);
        $device->status = 'authorized';
        $device->authorized_at = now();
        $device->authorized_by = $request->user()->id;
        $device->expires_at = now()->addDays($days);
    }
}
