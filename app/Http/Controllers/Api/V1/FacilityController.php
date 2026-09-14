<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\FacilityResource;
use App\Models\Facility;
use App\Models\Organization;
use Illuminate\Http\Request;

class FacilityController extends Controller
{
    public function index(Request $request)
    {
        return FacilityResource::collection(Facility::query()->where('tenant_id', $request->user()->tenant_id)->with('branches.courts')->paginate());
    }

    public function store(Request $request)
    {
        $data = $request->validate(['organization_id' => ['required', 'integer'], 'name' => ['required', 'string', 'max:120'], 'timezone' => ['nullable', 'timezone'], 'address' => ['nullable', 'string', 'max:1000']]);
        abort_unless(Organization::whereKey($data['organization_id'])->where('tenant_id', $request->user()->tenant_id)->exists(), 404);
        $facility = Facility::create($data + ['tenant_id' => $request->user()->tenant_id]);

        return (new FacilityResource($facility))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $facility)
    {
        $model = Facility::where('tenant_id', $request->user()->tenant_id)->findOrFail($facility);
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:120'], 'timezone' => ['sometimes', 'nullable', 'timezone'], 'address' => ['sometimes', 'nullable', 'string', 'max:1000']]);
        $model->update($data);

        return new FacilityResource($model);
    }

    public function destroy(Request $request, int $facility)
    {
        Facility::where('tenant_id', $request->user()->tenant_id)->findOrFail($facility)->delete();

        return response()->noContent();
    }
}
