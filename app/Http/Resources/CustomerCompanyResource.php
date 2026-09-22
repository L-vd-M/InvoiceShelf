<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerCompanyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'tax_id' => $this->tax_id,
            'vat_id' => $this->vat_id,
            'phone' => $this->phone,
            'website' => $this->website,
            'customers_count' => $this->when(isset($this->customers_count), $this->customers_count),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'address' => $this->when($this->relationLoaded('address') && $this->address, function () {
                return new AddressResource($this->address);
            }),
        ];
    }
}
