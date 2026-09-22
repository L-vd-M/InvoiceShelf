<?php

namespace App\Policies;

use App\Models\CustomerCompany;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Silber\Bouncer\BouncerFacade;

class CustomerCompanyPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return BouncerFacade::can('view-customer-company', CustomerCompany::class);
    }

    public function view(User $user, CustomerCompany $customerCompany): bool
    {
        return BouncerFacade::can('view-customer-company', $customerCompany) && $user->hasCompany($customerCompany->company_id);
    }

    public function create(User $user): bool
    {
        return BouncerFacade::can('create-customer-company', CustomerCompany::class);
    }

    public function update(User $user, CustomerCompany $customerCompany): bool
    {
        return BouncerFacade::can('edit-customer-company', $customerCompany) && $user->hasCompany($customerCompany->company_id);
    }

    public function delete(User $user, CustomerCompany $customerCompany): bool
    {
        return BouncerFacade::can('delete-customer-company', $customerCompany) && $user->hasCompany($customerCompany->company_id);
    }
}
