<?php

namespace App\Http\Resources;

use App\Models\DealLifecycle;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DealLifecycle */
class DealLifecycleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $owner = $this->owner_user_id ? User::find($this->owner_user_id, ['id', 'name', 'sales_team']) : null;

        return [
            'quote_id' => $this->first_quote_id,
            'customer_id' => $this->customer_id,
            'invoice_id' => $this->invoice_id,
            'outcome' => $this->outcome,
            'lost_reason' => $this->lost_reason,
            'owner' => $owner ? ['id' => $owner->id, 'name' => $owner->name, 'sales_team' => $owner->sales_team] : null,
            'created_at' => $this->iso($this->created_at),
            'first_sent_at' => $this->iso($this->first_sent_at),
            'accepted_at' => $this->iso($this->accepted_at),
            'invoiced_at' => $this->iso($this->invoiced_at),
            'first_paid_at' => $this->iso($this->first_paid_at),
            'fully_paid_at' => $this->iso($this->fully_paid_at),
            'revision_count' => $this->revision_count,
            'price_change_count' => $this->price_change_count,
            'first_total_minor' => $this->first_total_minor,
            'final_total_minor' => $this->final_total_minor,
            'discount_pct_first_to_final' => $this->discount_pct_first_to_final,
            'currency' => $this->currency,
            'built_at' => $this->iso($this->built_at),
        ];
    }

    private function iso($value): ?string
    {
        return $value?->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
