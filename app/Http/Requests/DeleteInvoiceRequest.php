<?php

namespace App\Http\Requests;

use App\Models\Invoice;
use App\Rules\RelationNotExist;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeleteInvoiceRequest extends FormRequest
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
            'reason' => [
                'required',
                'string',
                'min:3',
                'max:500',
            ],
            'ids' => [
                'required',
            ],
            'ids.*' => [
                'required',
                Rule::exists('invoices', 'id'),
                new RelationNotExist(Invoice::class, 'payments'),
            ],
        ];
    }
}
