<?php

namespace App\Support\Superadmin;

use App\Models\Agency;
use App\Models\License;
use App\Models\Pressing;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Hub superadmin (Figma 13) + listes pressings (13A) et utilisateurs réseau (13B).
 * Le centre des licences reste sur Figma 13C (`LicenseOverview`).
 */
class SuperadminHub
{
    public function overview(): array
    {
        $pressings = Pressing::query()->withCount(['agencies', 'users'])->get();
        $active = $pressings->where('status', true);
        $licenses = License::query()->with('pressing:id,name')->get();
        $expiring = $licenses->filter(fn (License $l) => $l->is_activated && $l->status && $l->expires_at
            && CarbonImmutable::parse($l->expires_at)->between(now(), now()->addDays((int) config('licensing.expiring_soon_days', 30))));
        $users = User::query()->whereNotNull('pressing_id')->count();
        $agencies = Agency::query()->where('status', true)->count();

        return [
            'kpis' => [
                'pressings' => ['value' => $active->count(), 'total' => $pressings->count()],
                'agencies' => ['value' => $agencies],
                'users' => ['value' => $users],
                'licenses' => [
                    'active' => $licenses->filter(fn (License $l) => $l->is_activated && $l->status)->count(),
                    'expiring' => $expiring->count(),
                    'suspended' => $licenses->where('status', false)->count(),
                ],
            ],
            'attention' => [
                'licenses_expiring' => $expiring->take(5)->map(fn (License $l) => [
                    'license_id' => $l->id,
                    'pressing' => $l->pressing?->name,
                    'expires_at' => optional($l->expires_at)->toIso8601String(),
                    'endpoint' => '/api/v1/superadmin/licenses/'.$l->id,
                ])->values()->all(),
                'inactive_pressings' => $pressings->where('status', false)->take(5)->map(fn (Pressing $p) => [
                    'pressing_id' => $p->id,
                    'name' => $p->name,
                    'endpoint' => '/api/v1/superadmin/pressings/'.$p->id,
                ])->values()->all(),
            ],
            'shortcuts' => [
                ['code' => 'pressings', 'label' => 'Pressings', 'endpoint' => '/api/v1/superadmin/pressings'],
                ['code' => 'agencies', 'label' => 'Agences', 'endpoint' => '/api/v1/superadmin/agencies'],
                ['code' => 'users', 'label' => 'Utilisateurs', 'endpoint' => '/api/v1/superadmin/users'],
                ['code' => 'licenses', 'label' => 'Licences', 'endpoint' => '/api/v1/superadmin/licenses'],
            ],
            'updated_at' => now()->toIso8601String(),
        ];
    }

    public function pressingRow(Pressing $p): array
    {
        $license = License::query()->where('pressing_id', $p->id)->latest('id')->first();
        $agencies = $p->agencies_count ?? $p->agencies()->count();
        $users = $p->users_count ?? $p->users()->count();

        return [
            'id' => $p->id,
            'name' => $p->name,
            'initials' => collect(explode(' ', (string) $p->name))->filter()->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->take(2)->implode(''),
            'status' => $p->status ? 'active' : 'inactive',
            'status_label' => $p->status ? 'Actif' : 'Inactif',
            'agencies' => $agencies,
            'users' => $users,
            'pricing_mode' => $p->pricing_mode,
            'license' => $license ? [
                'id' => $license->id,
                'plan' => $license->plan,
                'expires_at' => optional($license->expires_at)->toIso8601String(),
                'status' => $license->status && $license->is_activated ? 'active' : 'inactive',
            ] : null,
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }

    public function userRow(User $u): array
    {
        return [
            'id' => $u->id,
            'fullname' => $u->displayName(),
            'email' => $u->email,
            'role' => $u->roles->first()?->name,
            'pressing' => $u->pressing ? ['id' => $u->pressing->id, 'name' => $u->pressing->name] : null,
            'agency' => $u->agency ? ['id' => $u->agency->id, 'name' => $u->agency->name] : null,
            'status' => $u->status ? 'active' : 'inactive',
            'status_label' => $u->status ? 'Actif' : 'Inactif',
            'last_login_at' => $u->last_login_at?->toIso8601String(),
            'mfa_enabled' => $u->mfa_enabled_at !== null,
        ];
    }

    public function agencyRow(Agency $a): array
    {
        return [
            'id' => $a->id,
            'name' => $a->name,
            'code_prefix' => $a->code_prefix,
            'pressing' => $a->pressing ? ['id' => $a->pressing->id, 'name' => $a->pressing->name] : null,
            'country_code' => $a->country_code,
            'currency' => $a->currency,
            'status' => $a->status ? 'active' : 'inactive',
            'status_label' => $a->status ? 'Active' : 'Inactive',
            'opens_at' => $a->opens_at,
            'closes_at' => $a->closes_at,
        ];
    }
}
