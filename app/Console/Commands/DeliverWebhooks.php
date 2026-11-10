<?php

namespace App\Console\Commands;

use App\Jobs\DeliverWebhookJob;
use App\Models\WebhookDelivery;
use Illuminate\Console\Command;

class DeliverWebhooks extends Command
{
    protected $signature = 'webhooks:deliver {--limit=200}';

    protected $description = 'Queue every pending webhook delivery that is due';

    public function handle(): int
    {
        $ids = WebhookDelivery::where('state', WebhookDelivery::PENDING)
            ->where('next_attempt_at', '<=', now())
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->pluck('id');

        foreach ($ids as $id) {
            DeliverWebhookJob::dispatch($id);
        }

        $this->info("Queued {$ids->count()} webhook deliveries.");

        return self::SUCCESS;
    }
}
