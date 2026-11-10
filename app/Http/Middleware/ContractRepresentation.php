<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Middleware;

use App\Http\Controllers\Company\CreditNote\CreditNotesController;
use App\Http\Controllers\Company\Estimate\EstimatesController;
use App\Http\Controllers\Company\Invoice\InvoicesController;
use App\Http\Controllers\Company\Payment\PaymentsController;
use App\Http\Controllers\Contract\ContractDocumentsController;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Scoped (integration) tokens get the contract representation of the document endpoints instead of
 * the web UI's. The web UI, sessions and full-access tokens are untouched.
 */
class ContractRepresentation
{
    private const MAP = [
        EstimatesController::class.'@index' => ['list', 'estimate'],
        EstimatesController::class.'@store' => ['create', 'estimate'],
        EstimatesController::class.'@show' => ['show', 'estimate'],
        EstimatesController::class.'@accept' => ['accept'],
        InvoicesController::class.'@index' => ['list', 'invoice'],
        InvoicesController::class.'@store' => ['create', 'invoice'],
        InvoicesController::class.'@show' => ['show', 'invoice'],
        InvoicesController::class.'@issue' => ['issueInvoice'],
        CreditNotesController::class.'@index' => ['list', 'credit_note'],
        CreditNotesController::class.'@show' => ['show', 'credit_note'],
        CreditNotesController::class.'@store' => ['createCreditNote'],
        CreditNotesController::class.'@issue' => ['issueCreditNote'],
        PaymentsController::class.'@store' => ['createPayment'],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken || $token->can('*')) {
            return $next($request);
        }

        $route = $request->route();
        $target = self::MAP[$route->getActionName()] ?? null;

        if (! $target) {
            return $next($request);
        }

        [$method, $kind] = $target + [1 => null];
        $controller = app(ContractDocumentsController::class);

        if ($method === 'show') {
            $model = collect($route->parameters())->first(fn ($p) => $p instanceof \Illuminate\Database\Eloquent\Model);

            return $controller->show($request, $model);
        }

        if ($kind) {
            return $controller->{$method}($request, $kind);
        }

        return $controller->{$method}($request, ...array_values($route->parameters()));
    }
}
