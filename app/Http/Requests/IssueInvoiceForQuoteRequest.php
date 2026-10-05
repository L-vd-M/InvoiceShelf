<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IssueInvoiceForQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'integer', 'min:1'],
            'payment_method_id' => ['nullable', Rule::exists('payment_methods', 'id')],
            'notes' => ['nullable', 'string'],
            'send_email' => ['nullable', 'boolean'],
        ];
    }
}
