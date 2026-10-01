<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchHoliday;
use App\Models\Court;
use App\Models\CourtMaintenance;
use App\Models\CourtType;
use App\Models\OperatingHour;
use App\Models\PricingRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FacilityOperationsController extends Controller
{
    public function courtTypes(Request $request): JsonResponse
    {
        return response()->json(['data' => CourtType::where('tenant_id', $request->user()->tenant_id)->paginate()]);
    }

    public function createCourtType(Request $request): JsonResponse
    {
        $model = CourtType::create($request->validate(['name' => ['required', 'string', 'max:100'], 'sport' => ['nullable', 'string', 'max:60'], 'description' => ['nullable', 'string', 'max:1000']]) + ['tenant_id' => $request->user()->tenant_id]);

        return response()->json(['data' => $model], 201);
    }

    public function updateCourtType(Request $request, int $courtType): JsonResponse
    {
        $model = CourtType::where('tenant_id', $request->user()->tenant_id)->findOrFail($courtType);
        $model->update($request->validate(['name' => ['sometimes', 'string', 'max:100'], 'sport' => ['sometimes', 'string', 'max:60'], 'description' => ['sometimes', 'nullable', 'string', 'max:1000']]));

        return response()->json(['data' => $model]);
    }

    public function destroyCourtType(Request $request, int $courtType): JsonResponse
    {
        CourtType::where('tenant_id', $request->user()->tenant_id)->findOrFail($courtType)->delete();

        return response()->json(status: 204);
    }

    public function operatingHours(Request $request, int $branch): JsonResponse
    {
        $this->branch($request, $branch);

        return response()->json(['data' => OperatingHour::where('tenant_id', $request->user()->tenant_id)->where('branch_id', $branch)->orderBy('day_of_week')->get()]);
    }

    public function replaceOperatingHours(Request $request, int $branch): JsonResponse
    {
        $this->branch($request, $branch);
        $data = $request->validate(['hours' => ['required', 'array', 'size:7'], 'hours.*.day_of_week' => ['required', 'integer', 'between:0,6', 'distinct'], 'hours.*.opens_at' => ['nullable', 'date_format:H:i'], 'hours.*.closes_at' => ['nullable', 'date_format:H:i'], 'hours.*.is_closed' => ['required', 'boolean']]);
        foreach ($data['hours'] as $hour) {
            if (! $hour['is_closed']) {
                abort_unless(isset($hour['opens_at'], $hour['closes_at']) && $hour['opens_at'] < $hour['closes_at'], 422, 'Open days require a valid opening and closing time.');
            } OperatingHour::updateOrCreate(['branch_id' => $branch, 'day_of_week' => $hour['day_of_week']], $hour + ['tenant_id' => $request->user()->tenant_id]);
        }

        return $this->operatingHours($request, $branch);
    }

    public function holidays(Request $request, int $branch): JsonResponse
    {
        $this->branch($request, $branch);

        return response()->json(['data' => BranchHoliday::where('tenant_id', $request->user()->tenant_id)->where('branch_id', $branch)->orderBy('holiday_date')->get()]);
    }

    public function createHoliday(Request $request, int $branch): JsonResponse
    {
        $this->branch($request, $branch);
        $model = BranchHoliday::create($request->validate(['holiday_date' => ['required', 'date'], 'name' => ['required', 'string', 'max:150'], 'is_closed' => ['nullable', 'boolean']]) + ['tenant_id' => $request->user()->tenant_id, 'branch_id' => $branch]);

        return response()->json(['data' => $model], 201);
    }

    public function destroyHoliday(Request $request, int $holiday): JsonResponse
    {
        BranchHoliday::where('tenant_id', $request->user()->tenant_id)->findOrFail($holiday)->delete();

        return response()->json(status: 204);
    }

    public function maintenance(Request $request, int $court): JsonResponse
    {
        $this->court($request, $court);

        return response()->json(['data' => CourtMaintenance::where('tenant_id', $request->user()->tenant_id)->where('court_id', $court)->latest('starts_at')->get()]);
    }

    public function createMaintenance(Request $request, int $court): JsonResponse
    {
        $this->court($request, $court);
        $model = CourtMaintenance::create($request->validate(['starts_at' => ['required', 'date'], 'ends_at' => ['nullable', 'date', 'after:starts_at'], 'reason' => ['required', 'string', 'max:1000']]) + ['tenant_id' => $request->user()->tenant_id, 'court_id' => $court]);

        return response()->json(['data' => $model], 201);
    }

    public function updateMaintenance(Request $request, int $maintenance): JsonResponse
    {
        $model = CourtMaintenance::where('tenant_id', $request->user()->tenant_id)->findOrFail($maintenance);
        $model->update($request->validate(['ends_at' => ['sometimes', 'nullable', 'date'], 'status' => ['sometimes', 'in:scheduled,in_progress,completed,cancelled'], 'reason' => ['sometimes', 'string', 'max:1000']]));

        return response()->json(['data' => $model]);
    }

    public function pricingRules(Request $request, int $court): JsonResponse
    {
        $this->court($request, $court);

        return response()->json(['data' => PricingRule::where('tenant_id', $request->user()->tenant_id)->where('court_id', $court)->orderBy('day_of_week')->orderBy('starts_at')->get()]);
    }

    public function createPricingRule(Request $request, int $court): JsonResponse
    {
        $this->court($request, $court);
        $model = PricingRule::create($request->validate(['name' => ['required', 'string', 'max:120'], 'day_of_week' => ['nullable', 'integer', 'between:0,6'], 'starts_at' => ['nullable', 'date_format:H:i'], 'ends_at' => ['nullable', 'date_format:H:i'], 'price' => ['required', 'numeric', 'min:0'], 'is_active' => ['nullable', 'boolean']]) + ['tenant_id' => $request->user()->tenant_id, 'court_id' => $court]);

        return response()->json(['data' => $model], 201);
    }

    public function updatePricingRule(Request $request, int $pricingRule): JsonResponse
    {
        $model = PricingRule::where('tenant_id', $request->user()->tenant_id)->findOrFail($pricingRule);
        $model->update($request->validate(['name' => ['sometimes', 'string', 'max:120'], 'day_of_week' => ['sometimes', 'nullable', 'integer', 'between:0,6'], 'starts_at' => ['sometimes', 'nullable', 'date_format:H:i'], 'ends_at' => ['sometimes', 'nullable', 'date_format:H:i'], 'price' => ['sometimes', 'numeric', 'min:0'], 'is_active' => ['sometimes', 'boolean']]));

        return response()->json(['data' => $model]);
    }

    public function destroyPricingRule(Request $request, int $pricingRule): JsonResponse
    {
        PricingRule::where('tenant_id', $request->user()->tenant_id)->findOrFail($pricingRule)->delete();

        return response()->json(status: 204);
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
