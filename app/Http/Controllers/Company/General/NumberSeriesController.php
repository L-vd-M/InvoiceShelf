<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers\Company\General;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Services\Document\NumberSeries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The business prefix and the next number per document type of the company's series
 * (<PREFIX>-<TYPE>-YYYY-NNNNNN). Owner with a session or full-access token only.
 */
class NumberSeriesController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $this->guard($request);

        return response()->json($this->present((int) $request->header('company'), $this->year($request)));
    }

    public function update(Request $request): JsonResponse
    {
        $this->guard($request);
        $company = (int) $request->header('company');

        $data = $request->validate([
            'prefix' => ['sometimes', 'nullable', 'string', 'regex:'.NumberSeries::PREFIX_PATTERN],
            'year' => ['sometimes', 'integer', 'between:2000,2999'],
            'next' => ['sometimes', 'array'],
            'next.*' => ['integer', 'min:1', 'max:999999'],
        ]);

        $types = array_values(NumberSeries::TYPES);
        foreach (array_keys($data['next'] ?? []) as $type) {
            if (! in_array($type, $types, true)) {
                throw ValidationException::withMessages(['next' => ["Unknown document type $type; use ".implode(', ', $types).'.']]);
            }
        }

        $year = (int) ($data['year'] ?? NumberSeries::currentYear());

        DB::transaction(function () use ($data, $company, $year) {
            if (array_key_exists('prefix', $data)) {
                CompanySetting::setSettings([NumberSeries::SETTING => $data['prefix'] ?? ''], $company);
            }

            foreach ($data['next'] ?? [] as $type => $next) {
                NumberSeries::setNext($company, $type, $year, (int) $next);
            }
        });

        return response()->json($this->present($company, $year));
    }

    private function present(int $company, int $year): array
    {
        return ['prefix' => NumberSeries::prefix($company), 'year' => $year, 'series' => NumberSeries::status($company, $year)];
    }

    private function year(Request $request): int
    {
        return (int) $request->query('year', NumberSeries::currentYear());
    }

    private function guard(Request $request): void
    {
        $user = $request->user();
        $token = $user->currentAccessToken();
        $full = ! $token instanceof PersonalAccessToken || $token->can('*');

        abort_unless($user->isOwner() && $full, 403, 'Only an owner with a full-access session can manage the number series.');
    }
}
