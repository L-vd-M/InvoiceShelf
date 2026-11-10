<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Console\Commands;

use App\Models\IdempotencyKey;
use Illuminate\Console\Command;

class PruneIdempotencyKeys extends Command
{
    protected $signature = 'idempotency:prune';

    protected $description = 'Delete idempotency keys older than 24 hours';

    public function handle(): int
    {
        $count = IdempotencyKey::where('expires_at', '<', now())->delete();
        $this->info("Pruned {$count} idempotency keys.");

        return self::SUCCESS;
    }
}
