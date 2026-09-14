<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use App\Models\Facility;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function index(Request $request, int $facility)
    {
        $this->facility($request, $facility);

        return BranchResource::collection(Branch::where('tenant_id', $request->user()->tenant_id)->where('facility_id', $facility)->with('courts')->paginate());
    }

    public function store(Request $request, int $facility)
    {
        $this->facility($request, $facility);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'timezone' => ['nullable', 'timezone'], 'address' => ['nullable', 'string', 'max:1000']]);
        $branch = Branch::create($data + ['tenant_id' => $request->user()->tenant_id, 'facility_id' => $facility]);

        return (new BranchResource($branch))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $branch)
    {
        $model = $this->branch($request, $branch);
        $model->update($request->validate(['name' => ['sometimes', 'string', 'max:120'], 'timezone' => ['sometimes', 'nullable', 'timezone'], 'address' => ['sometimes', 'nullable', 'string', 'max:1000']]));

        return new BranchResource($model);
    }

    public function destroy(Request $request, int $branch)
    {
        $this->branch($request, $branch)->delete();

        return response()->noContent();
    }

    private function facility(Request $request, int $id): Facility
    {
        return Facility::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
    }

    private function branch(Request $request, int $id): Branch
    {
        return Branch::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
    }
}
