<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Link to a record in another TARCZA application: the pair (source, id), unique per company.
 */
trait HasExternalRef
{
    public function getExternalRefAttribute(): ?array
    {
        if ($this->external_ref_source === null) {
            return null;
        }

        return ['source' => $this->external_ref_source, 'id' => $this->external_ref_id];
    }

    /** Accepts "source:id" (the first colon separates the source). */
    public function scopeWhereExternalRef(Builder $query, string $ref): void
    {
        [$source, $id] = array_pad(explode(':', $ref, 2), 2, '');

        $query->where($this->getTable().'.external_ref_source', $source)
            ->where($this->getTable().'.external_ref_id', $id);
    }
}
