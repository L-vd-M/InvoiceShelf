<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CreditNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'credit_note_number' => $this->credit_note_number,
            'credit_note_date' => $this->credit_note_date,
            'status' => $this->status,
            'invoice_id' => $this->invoice_id,
            'invoice_number' => $this->whenLoaded('invoice', fn () => $this->invoice->invoice_number),
            'customer_id' => $this->customer_id,
            'customer' => $this->whenLoaded('customer', fn () => new CustomerResource($this->customer)),
            'reason_code' => $this->reason_code,
            'reason' => $this->reason,
            'sub_total' => $this->sub_total,
            'discount_val' => $this->discount_val,
            'tax' => $this->tax,
            'total' => $this->total,
            'applied_amount' => $this->applied_amount,
            'unapplied_amount' => $this->unapplied_amount,
            'currency_id' => $this->currency_id,
            'exchange_rate' => $this->exchange_rate,
            'sequence_number' => $this->sequence_number,
            'unique_hash' => $this->unique_hash,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id,
                'invoice_item_id' => $i->invoice_item_id,
                'name' => $i->name,
                'description' => $i->description,
                'quantity' => $i->quantity,
                'price' => $i->price,
                'discount_val' => $i->discount_val,
                'tax' => $i->tax,
                'total' => $i->total,
            ])),
            'created_at' => $this->created_at,
        ];
    }
}
