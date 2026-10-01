<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Facility;
use Illuminate\Http\Request;

class FacilityOnboardingController extends Controller
{
    public function update(Request $request, int $facility)
    {
        $data = $request->validate(['registration_open' => ['required', 'boolean']]);
        $model = Facility::where('tenant_id', $request->user()->tenant_id)->findOrFail($facility);
        abort_if($data['registration_open'] && ! $model->branches()->whereHas('courts', fn ($q) => $q->where('status', 'active'))->exists(), 422, 'Add a branch and an active court before opening player registration.');
        $model->update($data);

        return response()->json(['data' => $model->only(['id', 'registration_open'])]);
    }
}
