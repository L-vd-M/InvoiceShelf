<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;

trait HasExternalRefInput
{
    /** @return array<string, array> */
    protected function externalRefRules(string $table, ?int $ignoreId = null): array
    {
        return [
            'external_ref' => ['sometimes', 'nullable', 'array:source,id'],
            'external_ref.source' => ['required_with:external_ref', 'string', 'max:50', 'regex:/^[a-z0-9_.-]+$/'],
            'external_ref.id' => [
                'required_with:external_ref',
                'string',
                'max:191',
                Rule::unique($table, 'external_ref_id')
                    ->where('company_id', $this->header('company'))
                    ->where('external_ref_source', $this->input('external_ref.source'))
                    ->ignore($ignoreId),
            ],
        ];
    }

    /** Columns to merge into the payload; empty when the client did not send external_ref. */
    protected function externalRefColumns(): array
    {
        if (! $this->has('external_ref')) {
            return [];
        }

        $ref = $this->input('external_ref');

        return [
            'external_ref_source' => $ref['source'] ?? null,
            'external_ref_id' => $ref['id'] ?? null,
        ];
    }
}
