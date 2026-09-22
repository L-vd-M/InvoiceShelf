<?php

namespace App\Services;

use App\Http\Requests\CustomerCompanyRequest;
use App\Models\Address;
use App\Models\CustomerCompany;

class CustomerCompanyService
{
    public function create(CustomerCompanyRequest $request): CustomerCompany
    {
        $customerCompany = CustomerCompany::create($request->getCustomerCompanyPayload());

        if ($request->hasAddress()) {
            $customerCompany->addresses()->create($request->getAddress());
        }

        return CustomerCompany::with('address')->find($customerCompany->id);
    }

    /**
     * Updates the company record, then pushes the fresh info out to every
     * linked customer that has company_auto_update enabled. Customers
     * without auto-update are left alone -- they'll see a "company info
     * changed" prompt instead (Customer::hasStaleCompanyInfo()).
     */
    public function update(CustomerCompanyRequest $request, CustomerCompany $customerCompany): CustomerCompany
    {
        $customerCompany->update($request->getCustomerCompanyPayload());

        $customerCompany->addresses()->where('type', Address::BILLING_TYPE)->delete();

        if ($request->hasAddress()) {
            $customerCompany->addresses()->create($request->getAddress());
        }

        $customerCompany->refresh();

        foreach ($customerCompany->customers()->where('company_auto_update', true)->get() as $customer) {
            $customerCompany->applyTo($customer);
        }

        return CustomerCompany::with('address')->find($customerCompany->id);
    }

    public function delete(CustomerCompany $customerCompany): void
    {
        $customerCompany->addresses()->delete();
        $customerCompany->delete();
    }
}
