<?php

namespace App\Support;

class TenantContext
{
    public ?int $pressingId = null;

    public ?int $agencyId = null;

    public bool $bypass = false;

    public string $actor = 'guest';

    public function set(?int $pressingId, ?int $agencyId = null, bool $bypass = false, string $actor = 'guest'): void
    {
        $this->pressingId = $pressingId;
        $this->agencyId = $agencyId;
        $this->bypass = $bypass;
        $this->actor = $actor;
    }

    public function clear(): void
    {
        $this->pressingId = null;
        $this->agencyId = null;
        $this->bypass = false;
        $this->actor = 'guest';
    }

    public function applies(): bool
    {
        return ! $this->bypass && $this->pressingId !== null;
    }

    public function scopesToAgency(): bool
    {
        return $this->applies() && $this->agencyId !== null;
    }

    public function operatingAgencyId(): ?int
    {
        return $this->agencyId;
    }

    public function requireAgencyId(): int
    {
        if (! $this->agencyId) {
            abort(422, 'Une agence doit être précisée (X-Agency-Id) pour cette opération.');
        }

        return $this->agencyId;
    }
}
