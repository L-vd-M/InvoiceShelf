<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers\Company\Integration;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnforceTokenScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Per-integration API tokens. Only an owner using a session or full-access token may manage them,
 * so a scoped token can never mint a stronger one.
 */
class IntegrationTokensController extends Controller
{
    private const PREFIX = 'integration:';

    public function index(Request $request): JsonResponse
    {
        $this->guard($request);
        $company = (int) $request->header('company');

        $tokens = $request->user()->tokens()
            ->where('name', 'like', self::PREFIX.'%')
            ->get()
            ->filter(fn (PersonalAccessToken $t) => in_array("company:{$company}", $t->abilities, true))
            ->map(fn (PersonalAccessToken $t) => $this->present($t))
            ->values();

        return response()->json(['data' => $tokens]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->guard($request);
        $company = (int) $request->header('company');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in(EnforceTokenScope::ABILITIES)],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $token = $request->user()->createToken(
            self::PREFIX.$data['name'],
            array_values(array_unique([...$data['abilities'], "company:{$company}"])),
            isset($data['expires_at']) ? now()->parse($data['expires_at']) : null,
        );

        // The plain-text token is shown exactly once; only its hash is stored.
        return response()->json(['data' => $this->present($token->accessToken) + ['token' => $token->plainTextToken]], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->guard($request);
        $company = (int) $request->header('company');

        $token = $request->user()->tokens()->where('name', 'like', self::PREFIX.'%')->findOrFail($id);
        abort_unless(in_array("company:{$company}", $token->abilities, true), 404);
        $token->delete();

        return response()->json(['success' => true]);
    }

    private function guard(Request $request): void
    {
        $user = $request->user();
        $token = $user->currentAccessToken();
        $full = ! $token instanceof PersonalAccessToken || $token->can('*');

        abort_unless($user->isOwner() && $full, 403, 'Only an owner with a full-access session can manage integration tokens.');
    }

    private function present(PersonalAccessToken $token): array
    {
        return [
            'id' => $token->id,
            'name' => substr($token->name, strlen(self::PREFIX)),
            'abilities' => array_values(array_filter($token->abilities, fn ($a) => ! str_starts_with($a, 'company:'))),
            'expires_at' => $token->expires_at?->toIso8601String(),
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'created_at' => $token->created_at?->toIso8601String(),
        ];
    }
}
