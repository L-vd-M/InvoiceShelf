<?php

namespace App\Console\Commands;

use App\Services\Document\DealLifecycleService;
use Illuminate\Console\Command;

class RebuildDealLifecycles extends Command
{
    protected $signature = 'deals:rebuild {--company= : Only this company id}';

    protected $description = 'Recompute the deal lifecycle row of every quote from its revisions and payments. Idempotent; writes no events.';

    public function handle(): int
    {
        $company = $this->option('company');

        $this->info(DealLifecycleService::rebuildAll($company !== null ? (int) $company : null).' deal(s) rebuilt.');

        return self::SUCCESS;
    }
}
