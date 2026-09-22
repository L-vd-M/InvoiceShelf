<?php

namespace App\Http\Requests;

use App\Models\Address;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

class CustomerCompanyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
            ],
            'tax_id' => [
                'nullable',
            ],
            'vat_id' => [
                'nullable',
            ],
            'phone' => [
                'nullable',
            ],
            'website' => [
                'nullable',
            ],
            'address.address_street_1' => [
                'nullable',
            ],
            'address.address_street_2' => [
                'nullable',
            ],
            'address.city' => [
                'nullable',
            ],
            'address.state' => [
                'nullable',
            ],
            'address.country_id' => [
                'nullable',
            ],
            'address.zip' => [
                'nullable',
            ],
            'address.phone' => [
                'nullable',
            ],
        ];
    }

    public function getCustomerCompanyPayload(): array
    {
        return collect($this->validated())
            ->only(['name', 'tax_id', 'vat_id', 'phone', 'website'])
            ->merge([
                'creator_id' => $this->user()->id,
                'company_id' => $this->header('company'),
            ])
            ->toArray();
    }

    public function getAddress(): array
    {
        return collect($this->address ?? [])
            ->merge([
                'type' => Address::BILLING_TYPE,
            ])
            ->toArray();
    }

    public function hasAddress(): bool
    {
        $data = Arr::where($this->address ?? [], fn ($value) => isset($value) && $value !== '');

        return count($data) > 0;
    }
}
