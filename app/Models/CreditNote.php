<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Issued the moment it is created and never edited or deleted: a mistake is corrected by a new
 * document. Reduces what the customer owes on the invoice; anything beyond that is `unapplied_amount`
 * (money to refund or hold as customer credit).
 */
class CreditNote extends Model
{
    use HasFactory;

    public const STATUS_ISSUED = 'ISSUED';

    public const REASONS = [
        'goods_returned' => 'Goods returned',
        'price_correction' => 'Price correction',
        'billing_error' => 'Billing error',
        'service_not_delivered' => 'Service not delivered',
        'goodwill' => 'Goodwill',
        'other' => 'Other',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sub_total' => 'integer',
            'tax' => 'integer',
            'total' => 'integer',
            'discount_val' => 'integer',
            'applied_amount' => 'integer',
            'unapplied_amount' => 'integer',
            'exchange_rate' => 'float',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CreditNoteItem::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function scopeWhereCompany($query)
    {
        $query->where('credit_notes.company_id', request()->header('company'));
    }
}
