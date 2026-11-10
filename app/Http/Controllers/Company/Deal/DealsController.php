<?php

namespace App\Http\Controllers\Company\Deal;

use App\Http\Controllers\Controller;
use App\Http\Resources\DealLifecycleResource;
use App\Models\Company;
use App\Models\Estimate;
use App\Services\Document\DealLifecycleService;
use Illuminate\Http\Request;

/**
 * Deal outcome of a quote (spec 07 sections 3 and 6): read the lifecycle, mark lost, reassign.
 */
class DealsController extends Controller
{
    public function lifecycle(Estimate $estimate)
    {
        $this->authorize('view', $estimate);

        return new DealLifecycleResource(DealLifecycleService::ensure($estimate));
    }

    public function lost(Request $request, Estimate $estimate)
    {
        $this->authorize('update', $estimate);

        $data = $request->validate([
            'reason_code' => ['required', 'string', 'max:30'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return new DealLifecycleResource(DealLifecycleService::markLost($estimate, $data['reason_code'], $data['note'] ?? null));
    }

    public function assign(Request $request, Estimate $estimate)
    {
        $this->authorize('update', $estimate);

        $data = $request->validate(['user_id' => ['required', 'integer']]);

        return new DealLifecycleResource(DealLifecycleService::assign($estimate, (int) $data['user_id']));
    }

    public function lostReasons(Request $request)
    {
        $this->authorize('viewAny', Estimate::class);

        return response()->json(['data' => DealLifecycleService::lostReasons($request->header('company'))]);
    }

    /**
     * Salespeople a quote can be given to: the members of the company.
     */
    public function owners(Request $request)
    {
        $this->authorize('viewAny', Estimate::class);

        $owners = Company::findOrFail((int) $request->header('company'))->users()
            ->orderBy('users.name')->get(['users.id', 'users.name', 'users.sales_team'])
            ->map(fn ($user) => ['id' => $user->id, 'name' => $user->name, 'sales_team' => $user->sales_team]);

        return response()->json(['data' => $owners]);
    }
}
