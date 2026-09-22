<?php

namespace App\Models;

use App\Support\SafeOrderBy;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CustomerCompany extends Model
{
    use HasFactory;

    protected $guarded = [
        'id',
    ];

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function address(): HasOne
    {
        return $this->hasOne(Address::class)->where('type', Address::BILLING_TYPE);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeWhereCompany(Builder $query): void
    {
        $query->where('company_id', request()->header('company'));
    }

    public function scopeWhereSearch(Builder $query, string $search): void
    {
        $query->where('name', 'LIKE', '%'.$search.'%');
    }

    public function scopeApplyFilters(Builder $query, array $filters): void
    {
        $filters = collect($filters);

        if ($filters->get('search')) {
            $query->whereSearch($filters->get('search'));
        }

        if ($filters->get('orderByField') || $filters->get('orderBy')) {
            $field = $filters->get('orderByField') ? $filters->get('orderByField') : 'name';
            $orderBy = $filters->get('orderBy') ? $filters->get('orderBy') : 'asc';
            $query->whereOrder($field, $orderBy);
        }
    }

    public function scopeWhereOrder(Builder $query, string $orderByField, string $orderBy): void
    {
        SafeOrderBy::apply($query, $orderByField, $orderBy);
    }

    /**
     * @return Collection|LengthAwarePaginator
     */
    public function scopePaginateData(Builder $query, string $limit)
    {
        if ($limit == 'all') {
            return $query->get();
        }

        return $query->paginate($limit);
    }

    /**
     * Copy this company's stored info onto a customer: company_name, tax_id,
     * and address fields (creating/overwriting the customer's billing address).
     * Used both for one-time autofill at customer-create time and for pushing
     * updates out to customers with company_auto_update enabled.
     */
    public function applyTo(Customer $customer): void
    {
        $customer->company_name = $this->name;
        $customer->tax_id = $this->tax_id;
        $customer->company_synced_at = now();
        $customer->save();

        $companyAddress = $this->address;

        if (! $companyAddress) {
            return;
        }

        $customer->addresses()->where('type', Address::BILLING_TYPE)->delete();

        $customer->addresses()->create([
            'type' => Address::BILLING_TYPE,
            'name' => $companyAddress->name,
            'address_street_1' => $companyAddress->address_street_1,
            'address_street_2' => $companyAddress->address_street_2,
            'city' => $companyAddress->city,
            'state' => $companyAddress->state,
            'country_id' => $companyAddress->country_id,
            'zip' => $companyAddress->zip,
            'phone' => $companyAddress->phone,
            'fax' => $companyAddress->fax,
        ]);
    }
}
