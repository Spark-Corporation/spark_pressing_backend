<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/** Rapport programmé (bouton « Programmer » des rapports). */
class ReportSchedule extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'pressing_id', 'user_id', 'agency_id', 'report', 'frequency', 'format',
        'recipients', 'last_sent_at', 'next_run_at', 'active',
    ];

    protected $casts = [
        'recipients' => 'array',
        'last_sent_at' => 'datetime',
        'next_run_at' => 'datetime',
        'active' => 'boolean',
    ];

    public function tenantScopesToAgency(): bool
    {
        return false;
    }

    public static function nextRun(string $frequency, CarbonInterface $from): CarbonInterface
    {
        $base = $from->copy()->startOfDay()->setTime(7, 0);

        return match ($frequency) {
            'weekly' => $base->next(CarbonInterface::MONDAY)->setTime(7, 0),
            'monthly' => $base->addMonthNoOverflow()->startOfMonth()->setTime(7, 0),
            default => $base->addDay(),
        };
    }
}
