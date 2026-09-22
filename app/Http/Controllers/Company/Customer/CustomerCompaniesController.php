<?php

namespace App\Http\Controllers\Company\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerCompanyRequest;
use App\Http\Resources\CustomerCompanyResource;
use App\Models\CustomerCompany;
use App\Services\CustomerCompanyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerCompaniesController extends Controller
{
    public function __construct(
        private readonly CustomerCompanyService $customerCompanyService,
    ) {}

    /**
     * Display a listing of the resource.
     *
     * @return JsonResponse
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', CustomerCompany::class);

        $limit = $request->has('limit') ? $request->limit : 10;

        $customerCompanies = CustomerCompany::with('address')
            ->whereCompany()
            ->applyFilters($request->all())
            ->withCount('customers')
            ->paginateData($limit);

        return CustomerCompanyResource::collection($customerCompanies);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return JsonResponse
     */
    public function store(CustomerCompanyRequest $request)
    {
        $this->authorize('create', CustomerCompany::class);

        $customerCompany = $this->customerCompanyService->create($request);

        return new CustomerCompanyResource($customerCompany);
    }

    /**
     * Display the specified resource.
     *
     * @return JsonResponse
     */
    public function show(CustomerCompany $customerCompany)
    {
        $this->authorize('view', $customerCompany);

        return new CustomerCompanyResource($customerCompany->load('address'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @return JsonResponse
     */
    public function update(CustomerCompanyRequest $request, CustomerCompany $customerCompany)
    {
        $this->authorize('update', $customerCompany);

        $customerCompany = $this->customerCompanyService->update($request, $customerCompany);

        return new CustomerCompanyResource($customerCompany);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @return JsonResponse
     */
    public function destroy(CustomerCompany $customerCompany)
    {
        $this->authorize('delete', $customerCompany);

        $this->customerCompanyService->delete($customerCompany);

        return response()->json(['success' => true]);
    }
}
