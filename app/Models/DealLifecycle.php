<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Derived record of one quote's path to an outcome (spec 07 section 3). Rebuilt by
 * DealLifecycleService from quotes, revisions and payments, so it can be corrected without
 * touching history.
 */
class DealLifecycle extends Model
{
    public const OUTCOMES = ['open', 'won', 'lost', 'expired'];

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'datetime',
        'first_sent_at' => 'datetime',
        'accepted_at' => 'datetime',
        'invoiced_at' => 'datetime',
        'first_paid_at' => 'datetime',
        'fully_paid_at' => 'datetime',
        'built_at' => 'datetime',
        'revision_count' => 'integer',
        'price_change_count' => 'integer',
        'first_total_minor' => 'integer',
        'final_total_minor' => 'integer',
        'discount_pct_first_to_final' => 'float',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
