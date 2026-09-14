<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CourtResource;
use App\Models\Branch;
use App\Models\Court;
use Illuminate\Http\Request;

class CourtController extends Controller
{
    public function index(Request $request, int $branch)
    {
        $this->branch($request, $branch);

        return CourtResource::collection(Court::where('tenant_id', $request->user()->tenant_id)->where('branch_id', $branch)->paginate());
    }

    public function store(Request $request, int $branch)
    {
        $this->branch($request, $branch);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'sport' => ['nullable', 'string', 'max:60'], 'status' => ['nullable', 'in:active,maintenance,inactive'], 'base_price' => ['required', 'numeric', 'min:0']]);
        $court = Court::create($data + ['tenant_id' => $request->user()->tenant_id, 'branch_id' => $branch]);

        return (new CourtResource($court))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $court)
    {
        $model = $this->court($request, $court);
        $model->update($request->validate(['name' => ['sometimes', 'string', 'max:120'], 'sport' => ['sometimes', 'string', 'max:60'], 'status' => ['sometimes', 'in:active,maintenance,inactive'], 'base_price' => ['sometimes', 'numeric', 'min:0']]));

        return new CourtResource($model);
    }

    public function destroy(Request $request, int $court)
    {
        $this->court($request, $court)->delete();

        return response()->noContent();
    }

    private function branch(Request $request, int $id): Branch
    {
        return Branch::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
    }

    private function court(Request $request, int $id): Court
    {
        return Court::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
    }
}
