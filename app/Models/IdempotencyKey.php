<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'locked_at' => 'datetime',
            'expires_at' => 'datetime',
            'response_headers' => 'array',
        ];
    }
}
