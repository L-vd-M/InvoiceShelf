<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\Events\WebhookDeliveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $deliveryId) {}

    public function handle(WebhookDeliveryService $service): void
    {
        if ($delivery = WebhookDelivery::find($this->deliveryId)) {
            $service->deliver($delivery);
        }
    }
}
