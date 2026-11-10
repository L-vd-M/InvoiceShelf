<?php

namespace App\Console\Commands;

use App\Services\Document\RevisionService;
use Illuminate\Console\Command;

class BackfillDocumentRevisions extends Command
{
    protected $signature = 'documents:backfill-revisions';

    protected $description = 'Write a first "created" revision for every quote, invoice and credit note that has no history yet.';

    public function handle(): int
    {
        $this->info(RevisionService::backfill().' document(s) backfilled.');

        return self::SUCCESS;
    }
}
