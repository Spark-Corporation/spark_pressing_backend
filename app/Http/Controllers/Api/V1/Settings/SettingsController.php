<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Controller;
use App\Http\Resources\AgencyResource;
use App\Http\Resources\PressingResource;
use App\Models\Agency;
use App\Models\License;
use App\Models\Pressing;
use App\Support\Money\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Knuckles\Scribe\Attributes\Group;

#[Group('Organisation')]
class SettingsController extends Controller
{
    public function pressing(Request $request): JsonResponse
    {
        $pressing = Pressing::query()->findOrFail($request->user()->pressing_id);

        return $this->ok((new PressingResource($pressing))->resolve());
    }

    public function updatePressing(Request $request): JsonResponse
    {
        $pressing = Pressing::query()->findOrFail($request->user()->pressing_id);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            'details' => ['nullable', 'string'],
            'pricing_mode' => ['sometimes', 'in:piece,kilo,mixed'],
            'workflow_laveur_enabled' => ['sometimes', 'boolean'],
            'workflow_classeur_enabled' => ['sometimes', 'boolean'],
            'qr_labels_enabled' => ['sometimes', 'boolean'],
            'block_retrieve_if_unpaid' => ['sometimes', 'boolean'],
            'loyalty_points_rate' => ['sometimes', 'integer', 'min:0'],
            'loyalty_redeem_threshold' => ['sometimes', 'integer', 'min:1'],
            'loyalty_redeem_value' => ['sometimes', 'integer', 'min:0'],
            'hours_classic' => ['sometimes', 'integer', 'min:1'],
            'hours_express' => ['sometimes', 'integer', 'min:1'],
            'hours_repass' => ['sometimes', 'integer', 'min:1'],
            'collection_fee' => ['sometimes', 'integer', 'min:0'],
            'delivery_fee' => ['sometimes', 'integer', 'min:0'],
            'reporting_currency' => ['nullable', 'string', 'size:3'],
            'primary_color' => ['nullable', 'string', 'max:16'],
            'secondary_color' => ['nullable', 'string', 'max:16'],
        ]);

        if (! empty($data['reporting_currency'])) {
            $data['reporting_currency'] = strtoupper($data['reporting_currency']);
        }

        $pressing->update($data);

        return $this->ok((new PressingResource($pressing))->resolve());
    }

    public function license(Request $request): JsonResponse
    {
        $license = License::query()
            ->where('pressing_id', $request->user()->pressing_id)
            ->latest()
            ->first();

        return $this->ok($license ? [
            'plan' => $license->plan,
            'seats' => $license->seats,
            'is_activated' => $license->is_activated,
            'is_valid' => $license->isValid(),
            'expires_at' => $license->expires_at?->toIso8601String(),
        ] : null);
    }

    public function updateAgency(Request $request, Agency $agency): JsonResponse
    {
        abort_unless((int) $agency->pressing_id === (int) $request->user()->pressing_id, 403);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            'address' => ['nullable', 'string'],
            'contact' => ['nullable', 'string'],
            'currency' => ['nullable', 'string', 'size:3'],
            'code_prefix' => ['nullable', 'string', 'max:8'],
            'code_suffix' => ['nullable', 'string', 'max:8'],
            'status' => ['sometimes', 'boolean'],
        ]);

        if (! empty($data['currency'])) {
            $data['currency'] = Currency::normalize($data['currency']);
            if (! Currency::isValid($data['currency'])) {
                throw ValidationException::withMessages([
                    'currency' => 'Code devise ISO 4217 invalide.',
                ]);
            }
        }

        $agency->update($data);

        return $this->ok((new AgencyResource($agency))->resolve());
    }
}
