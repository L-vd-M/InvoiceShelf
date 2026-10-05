<?php

namespace App\Http\Requests;

use App\Models\Invoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeleteEstimatesRequest extends FormRequest
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
            'ids' => [
                'required',
            ],
            'ids.*' => [
                'required',
                Rule::exists('estimates', 'id'),
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (Invoice::where('estimate_id', $value)->exists()) {
                        $fail('This estimate has a linked invoice and cannot be deleted.');
                    }
                },
            ],
        ];
    }
}
