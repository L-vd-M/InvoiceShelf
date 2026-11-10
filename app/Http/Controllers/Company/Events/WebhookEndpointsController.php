<?php

namespace App\Http\Controllers\Company\Events;

use App\Http\Controllers\Controller;
use App\Models\WebhookEndpoint;
use App\Services\Events\OutboxService;
use App\Services\Events\WebhookUrlGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WebhookEndpointsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        return response()->json([
            'data' => WebhookEndpoint::where('company_id', $this->companyId($request))->orderBy('id')->get()
                ->map(fn (WebhookEndpoint $endpoint) => $this->present($endpoint)),
            'next_cursor' => null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $data = $this->validated($request);

        $secret = WebhookEndpoint::generateSecret();
        $endpoint = WebhookEndpoint::create($data + [
            'company_id' => $this->companyId($request),
            'secret' => $secret,
            'enabled' => $data['enabled'] ?? true,
        ]);

        return response()->json($this->present($endpoint) + ['secret' => $secret], 201);
    }

    public function update(Request $request, WebhookEndpoint $webhookEndpoint): JsonResponse
    {
        $this->authorizeManage($request, $webhookEndpoint);
        $webhookEndpoint->update($this->validated($request, true));

        return response()->json($this->present($webhookEndpoint->refresh()));
    }

    public function destroy(Request $request, WebhookEndpoint $webhookEndpoint): JsonResponse
    {
        $this->authorizeManage($request, $webhookEndpoint);
        $webhookEndpoint->delete();

        return response()->json(['success' => true]);
    }

    public function rotate(Request $request, WebhookEndpoint $webhookEndpoint): JsonResponse
    {
        $this->authorizeManage($request, $webhookEndpoint);

        $secret = WebhookEndpoint::generateSecret();
        $webhookEndpoint->update(['secret' => $secret, 'secret_rotated_at' => now()]);

        return response()->json($this->present($webhookEndpoint->refresh()) + ['secret' => $secret]);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'url' => [$required, 'string', 'max:2048', 'url', function (string $attribute, mixed $value, \Closure $fail) {
                if ($reason = WebhookUrlGuard::blockedReason((string) $value)) {
                    $fail($reason);
                }
            }],
            'description' => ['nullable', 'string', 'max:255'],
            'event_types' => ['nullable', 'array'],
            'event_types.*' => ['string', Rule::in([...OutboxService::TYPES, '*'])],
            'catalogue_version' => ['sometimes', 'string', 'max:10'],
            'enabled' => ['sometimes', 'boolean'],
        ]);
    }

    private function present(WebhookEndpoint $endpoint): array
    {
        return [
            'id' => (string) $endpoint->id,
            'url' => $endpoint->url,
            'description' => $endpoint->description,
            'event_types' => $endpoint->event_types,
            'enabled' => $endpoint->enabled,
            'secret_rotated_at' => $endpoint->secret_rotated_at,
            'created_at' => $endpoint->created_at,
        ];
    }

    private function companyId(Request $request): int
    {
        return (int) $request->header('company');
    }

    private function authorizeManage(Request $request, ?WebhookEndpoint $endpoint = null): void
    {
        $user = $request->user();
        abort_unless($user->isOwner() && $user->tokenCan('webhooks:manage'), 403);
        abort_if($endpoint && $endpoint->company_id !== $this->companyId($request), 404);
    }
}
