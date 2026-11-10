<?php

namespace App\Http\Controllers\CustomerPortal\Estimate;

use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\EstimateResource;
use App\Models\Company;
use App\Models\Estimate;
use App\Services\Document\EstimateService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class AcceptEstimateController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * @param  Estimate  $estimate
     * @return Response
     */
    public function __invoke(Request $request, Company $company, $id)
    {
        $estimate = $company->estimates()
            ->whereCustomer(Auth::guard('customer')->id())
            ->where('id', $id)
            ->first();

        if (! $estimate) {
            return response()->json(['error' => 'estimate_not_found'], 404);
        }

        $status = $request->validate(['status' => ['required', 'in:'.Estimate::STATUS_ACCEPTED.','.Estimate::STATUS_REJECTED]])['status'];

        // through the service so the quote.accepted / deal.lost events and the revision are written
        app(EstimateService::class)->changeStatus($estimate, $status);
        $estimate->refresh();

        return new EstimateResource($estimate);
    }
}
