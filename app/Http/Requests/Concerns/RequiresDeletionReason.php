<?php

namespace App\Http\Requests\Concerns;

use App\Models\DocumentDeletion;
use Illuminate\Validation\Rule;

trait RequiresDeletionReason
{
    protected function deletionReasonRules(): array
    {
        return [
            'reason_code' => ['required', 'string', Rule::in(array_keys(DocumentDeletion::REASONS))],
            'reason' => ['required_if:reason_code,'.DocumentDeletion::REASON_OTHER, 'nullable', 'string', 'min:3', 'max:500'],
        ];
    }

    public function reasonText(): string
    {
        return DocumentDeletion::reasonText($this->input('reason_code'), $this->input('reason'));
    }
}
