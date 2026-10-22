<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CashMovement extends \Illuminate\Database\Eloquent\Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'client_uuid',
        'pressing_id',
        'agency_id',
        'user_id',
        'category_id',
        'label',
        'type',
        'amount',
        'action_date',
        'validated',
        'payment_method',
        'beneficiary',
        'reference',
        'comment',
        'proof_path',
        'requires_approval',
        'approved_by',
        'approved_at',
        'cash_session_id',
    ];

    protected $casts = [
        'action_date' => 'datetime',
        'validated' => 'boolean',
        'requires_approval' => 'boolean',
        'approved_at' => 'datetime',
    ];

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return array{code:string,label:string} Validé / En attente de double contrôle / À valider */
    public function workflowStatus(): array
    {
        return match (true) {
            (bool) $this->validated => ['code' => 'validated', 'label' => 'Validé'],
            (bool) $this->requires_approval => ['code' => 'pending_approval', 'label' => 'En attente'],
            default => ['code' => 'to_validate', 'label' => 'À valider'],
        };
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CashMovementCategory::class, 'category_id');
    }
}
