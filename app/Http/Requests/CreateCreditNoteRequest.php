<?php

namespace App\Http\Requests;

use App\Models\CreditNote;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateCreditNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason_code' => ['required', 'string', Rule::in(array_keys(CreditNote::REASONS))],
            'reason' => ['required_if:reason_code,other', 'nullable', 'string', 'min:3', 'max:500'],
            'credit_note_date' => ['nullable', 'date'],
            // omitted = credit everything still creditable
            'items' => ['nullable', 'array', 'min:1'],
            'items.*.invoice_item_id' => ['required_with:items', 'integer'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'gt:0'],
        ];
    }
}
