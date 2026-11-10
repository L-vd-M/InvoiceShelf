<?php

namespace App\Http\Controllers\Company\Events;

use App\Http\Controllers\Controller;
use App\Models\WebhookDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookDeliveriesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $query = WebhookDelivery::with('event:id,event_id,type')
            ->where('company_id', (int) $request->header('company'))
            ->orderByDesc('id');

        if ($state = $request->query('state')) {
            $query->where('state', $state === 'failed' ? WebhookDelivery::DEAD : $state);
        }

        $page = $query->paginate(min((int) $request->query('limit', 25), 200));

        return response()->json([
            'data' => $page->getCollection()->map(fn (WebhookDelivery $d) => $this->present($d))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function retry(Request $request, WebhookDelivery $webhookDelivery): JsonResponse
    {
        $this->authorizeManage($request, $webhookDelivery);

        if ($webhookDelivery->state === WebhookDelivery::DELIVERED) {
            return response()->json(['message' => 'Already delivered.'], 409);
        }

        $webhookDelivery->update([
            'state' => WebhookDelivery::PENDING,
            'window_started_at' => now(),
            'next_attempt_at' => now(),
        ]);

        return response()->json(['data' => $this->present($webhookDelivery->refresh()->load('event:id,event_id,type'))]);
    }

    private function present(WebhookDelivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'event_id' => $delivery->event?->event_id,
            'type' => $delivery->event?->type,
            'webhook_endpoint_id' => $delivery->webhook_endpoint_id,
            'state' => $delivery->state === WebhookDelivery::DEAD ? 'failed' : $delivery->state,
            'attempts' => $delivery->attempts,
            'last_status' => $delivery->last_status,
            'last_error' => $delivery->last_error,
            'next_attempt_at' => $delivery->next_attempt_at,
            'delivered_at' => $delivery->delivered_at,
        ];
    }

    private function authorizeManage(Request $request, ?WebhookDelivery $delivery = null): void
    {
        $user = $request->user();
        abort_unless($user->isOwner() && $user->tokenCan('webhooks:manage'), 403);
        abort_if($delivery && $delivery->company_id !== (int) $request->header('company'), 404);
    }
}
