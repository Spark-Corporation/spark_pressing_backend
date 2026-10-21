<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicensePayment extends Model
{
    public const STATUS_LABELS = [
        'pending' => 'En attente',
        'paid' => 'Payé',
        'rejected' => 'Rejeté',
    ];

    protected $fillable = [
        'license_id', 'pressing_id', 'plan', 'periodicity', 'amount', 'taxes', 'currency',
        'payment_method', 'reference', 'invoice_number', 'status', 'period_start',
        'period_end', 'proof_path', 'recorded_by_type', 'recorded_by_id', 'paid_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'taxes' => 'integer',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'paid_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::created(function (LicensePayment $payment) {
            if (! $payment->invoice_number) {
                $payment->forceFill([
                    'invoice_number' => sprintf('FAC-%s-%03d', $payment->created_at->format('Y'), $payment->id),
                ])->saveQuietly();
            }
        });
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public function pressing(): BelongsTo
    {
        return $this->belongsTo(Pressing::class);
    }

    public function total(): int
    {
        return $this->amount + $this->taxes;
    }
}
