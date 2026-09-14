<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    public function index(Request $request)
    {
        return OrganizationResource::collection(Organization::where('tenant_id', $request->user()->tenant_id)->paginate());
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $organization = Organization::create($data + ['tenant_id' => $request->user()->tenant_id]);

        return (new OrganizationResource($organization))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $organization)
    {
        $model = $this->organization($request, $organization);
        $model->update($request->validate(['name' => ['required', 'string', 'max:120']]));

        return new OrganizationResource($model);
    }

    public function destroy(Request $request, int $organization)
    {
        $this->organization($request, $organization)->delete();

        return response()->noContent();
    }

    private function organization(Request $request, int $id): Organization
    {
        return Organization::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
    }
}
